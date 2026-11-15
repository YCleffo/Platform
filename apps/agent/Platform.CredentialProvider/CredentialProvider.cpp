// VM-only integration component. This DLL has no registration/installation entry point
// and never filters or replaces Windows' built-in administrator credential providers.
#include <windows.h>
#include <credentialprovider.h>
#include <ntsecapi.h>
#include <wincred.h>
#include <wincrypt.h>
#include <shlwapi.h>
#include <winrt/Windows.Data.Json.h>
#include <atomic>
#include <cstdint>
#include <cstring>
#include <new>
#include <string>
#include <vector>
#include "LegacyInstallation.h"

namespace {
const CLSID ProviderId = {0xa14bf3c8, 0xe945, 0x47c4, {0x83, 0xab, 0x38, 0xcf, 0x95, 0xbb, 0x7f, 0x02}};
std::atomic<long> Objects{0};
using winrt::Windows::Data::Json::JsonObject;
// Raw literals keep the Win32 pipe prefix intact; ordinary "\\.\pipe" literals
// silently turn \p into an unknown escape under MSVC.
constexpr wchar_t StatusPipe[] = LR"(\\.\pipe\platform-agent)";
constexpr wchar_t LogonPipe[] = LR"(\\.\pipe\platform-agent-logon)";

struct Handle {
    HANDLE value{INVALID_HANDLE_VALUE};
    ~Handle() { if (value != INVALID_HANDLE_VALUE && value != nullptr) CloseHandle(value); }
};
struct SecretBytes {
    std::vector<BYTE> bytes;
    ~SecretBytes() { if (!bytes.empty()) SecureZeroMemory(bytes.data(), bytes.size()); }
};
struct SecretString {
    std::wstring value;
    ~SecretString() { if (!value.empty()) SecureZeroMemory(value.data(), value.size() * sizeof(wchar_t)); }
};

void Require(bool condition) { if (!condition) winrt::throw_hresult(E_ACCESSDENIED); }
std::wstring NewId() {
    GUID id{}; winrt::check_hresult(CoCreateGuid(&id));
    wchar_t value[40]{}; StringFromGUID2(id, value, ARRAYSIZE(value));
    return value;
}
void CheckServer(HANDLE pipe) {
    ULONG pid{};
    winrt::check_bool(GetNamedPipeServerProcessId(pipe, &pid));
    Handle process{OpenProcess(PROCESS_QUERY_LIMITED_INFORMATION, FALSE, pid)};
    winrt::check_bool(process.value != nullptr);
    Handle token;
    winrt::check_bool(OpenProcessToken(process.value, TOKEN_QUERY, &token.value));
    DWORD length{}; GetTokenInformation(token.value, TokenUser, nullptr, 0, &length);
    std::vector<BYTE> buffer(length);
    winrt::check_bool(GetTokenInformation(token.value, TokenUser, buffer.data(), length, &length));
    Require(IsWellKnownSid(reinterpret_cast<TOKEN_USER*>(buffer.data())->User.Sid, WinLocalSystemSid));
}
JsonObject Call(const wchar_t* name, const JsonObject& request) {
    winrt::check_bool(WaitNamedPipeW(name, 2000));
    Handle pipe{CreateFileW(name, GENERIC_READ | GENERIC_WRITE, 0, nullptr, OPEN_EXISTING,
        SECURITY_SQOS_PRESENT | SECURITY_IMPERSONATION, nullptr)};
    winrt::check_bool(pipe.value != INVALID_HANDLE_VALUE);
    CheckServer(pipe.value); // A process squatting on the pipe cannot collect an authorization request.
    auto utf8 = winrt::to_string(request.Stringify()) + "\n";
    DWORD written{};
    winrt::check_bool(WriteFile(pipe.value, utf8.data(), static_cast<DWORD>(utf8.size()), &written, nullptr));
    Require(written == utf8.size());
    std::string response;
    char next{}; DWORD read{};
    while (response.size() < 16384) {
        winrt::check_bool(ReadFile(pipe.value, &next, 1, &read, nullptr));
        Require(read == 1);
        if (next == '\n') return JsonObject::Parse(winrt::to_hstring(response));
        response.push_back(next);
    }
    winrt::throw_hresult(E_INVALIDARG);
}
JsonObject Request(const std::wstring& requestId) {
    JsonObject request;
    request.SetNamedValue(L"version", winrt::Windows::Data::Json::JsonValue::CreateNumberValue(1));
    request.SetNamedValue(L"requestId", winrt::Windows::Data::Json::JsonValue::CreateStringValue(requestId));
    return request;
}

struct Grant {
    std::wstring session, sid, username;
    SecretString password;
};
void ReadGrant(Grant& grant) {
    const auto requestId = NewId();
    auto statusRequest = Request(requestId);
    statusRequest.SetNamedValue(L"command", winrt::Windows::Data::Json::JsonValue::CreateStringValue(L"status"));
    const auto status = Call(StatusPipe, statusRequest);
    Require(status.GetNamedString(L"requestId") == requestId && status.GetNamedBoolean(L"ok"));
    const auto state = status.GetNamedObject(L"state");
    Require(!state.GetNamedBoolean(L"testAdapter") && !state.GetNamedBoolean(L"recoveryPending")
        && state.GetNamedBoolean(L"serverConnected") && state.GetNamedString(L"sessionStatus") == L"SYNCING_IN");
    grant.session = state.GetNamedString(L"sessionId");
    auto authorize = Request(requestId);
    authorize.SetNamedValue(L"sessionId", winrt::Windows::Data::Json::JsonValue::CreateStringValue(grant.session));
    const auto response = Call(LogonPipe, authorize);
    Require(response.GetNamedNumber(L"version") == 1 && response.GetNamedString(L"requestId") == requestId
        && response.GetNamedString(L"sessionId") == grant.session);
    const auto base64 = response.GetNamedString(L"protectedCredential");
    DWORD size{};
    winrt::check_bool(CryptStringToBinaryW(base64.c_str(), base64.size(), CRYPT_STRING_BASE64, nullptr, &size, nullptr, nullptr));
    Require(size > 0 && size <= 8192);
    SecretBytes encrypted;
    encrypted.bytes.resize(size);
    winrt::check_bool(CryptStringToBinaryW(base64.c_str(), base64.size(), CRYPT_STRING_BASE64, encrypted.bytes.data(), &size, nullptr, nullptr));
    DATA_BLOB input{size, encrypted.bytes.data()}, plaintext{};
    winrt::check_bool(CryptUnprotectData(&input, nullptr, nullptr, nullptr, nullptr, CRYPTPROTECT_UI_FORBIDDEN, &plaintext));
    SecretBytes decoded;
    decoded.bytes.assign(plaintext.pbData, plaintext.pbData + plaintext.cbData);
    SecureZeroMemory(plaintext.pbData, plaintext.cbData); LocalFree(plaintext.pbData);
    const auto* cursor = decoded.bytes.data();
    const auto* end = cursor + decoded.bytes.size();
    auto read32 = [&]() { Require(end - cursor >= 4); uint32_t value; memcpy(&value, cursor, 4); cursor += 4; return value; };
    Require(read32() == 0x31474c50);
    Require(end - cursor >= 8);
    uint64_t expiry{}; memcpy(&expiry, cursor, 8); cursor += 8;
    FILETIME nowTime{}; GetSystemTimeAsFileTime(&nowTime);
    ULARGE_INTEGER now{}; now.LowPart = nowTime.dwLowDateTime; now.HighPart = nowTime.dwHighDateTime;
    Require(expiry > now.QuadPart && expiry <= now.QuadPart + 31ULL * 10000000ULL);
    auto field = [&]() {
        const auto length = read32();
        Require(length <= 2048 && length % 2 == 0 && end - cursor >= length);
        std::wstring value(reinterpret_cast<const wchar_t*>(cursor), length / 2); cursor += length;
        Require(value.find(L'\0') == std::wstring::npos);
        return value;
    };
    Require(field() == grant.session);
    Require(!field().empty()); // nonce is generated for this single outstanding grant
    grant.sid = field();
    Require(grant.sid == response.GetNamedString(L"sid"));
    grant.username = field();
    Require(IsManagedAccountName(grant.username) && grant.username.size() <= 20);
    grant.password.value = field();
    Require(!grant.password.value.empty() && cursor == end);
}

HRESULT SerializeGrant(const Grant& grant, CREDENTIAL_PROVIDER_CREDENTIAL_SERIALIZATION* output) {
    // Password protection follows the system password provider contract. Windows performs LSA logon.
    DWORD protectedSize{}; CRED_PROTECTION_TYPE protection{};
    CredProtectW(FALSE, const_cast<PWSTR>(grant.password.value.c_str()), static_cast<DWORD>(grant.password.value.size() + 1),
        nullptr, &protectedSize, &protection);
    if (GetLastError() != ERROR_INSUFFICIENT_BUFFER) return HRESULT_FROM_WIN32(GetLastError());
    SecretString protectedPassword; protectedPassword.value.resize(protectedSize);
    if (!CredProtectW(FALSE, const_cast<PWSTR>(grant.password.value.c_str()), static_cast<DWORD>(grant.password.value.size() + 1),
        protectedPassword.value.data(), &protectedSize, &protection)) return HRESULT_FROM_WIN32(GetLastError());
    protectedPassword.value.resize(wcslen(protectedPassword.value.c_str()));
    wchar_t computer[MAX_COMPUTERNAME_LENGTH + 1]{}; DWORD computerSize = ARRAYSIZE(computer);
    if (!GetComputerNameW(computer, &computerSize)) return HRESULT_FROM_WIN32(GetLastError());
    const std::wstring domain(computer);
    const size_t total = sizeof(KERB_INTERACTIVE_UNLOCK_LOGON) +
        (domain.size() + grant.username.size() + protectedPassword.value.size()) * sizeof(wchar_t);
    auto* buffer = static_cast<BYTE*>(CoTaskMemAlloc(total));
    if (!buffer) return E_OUTOFMEMORY;
    ZeroMemory(buffer, total);
    auto* logon = reinterpret_cast<KERB_INTERACTIVE_UNLOCK_LOGON*>(buffer);
    logon->Logon.MessageType = KerbInteractiveLogon;
    auto* cursor = buffer + sizeof(*logon);
    auto pack = [&](UNICODE_STRING& target, const std::wstring& value) {
        target.Length = target.MaximumLength = static_cast<USHORT>(value.size() * sizeof(wchar_t));
        memcpy(cursor, value.data(), target.Length);
        target.Buffer = reinterpret_cast<PWSTR>(cursor - buffer); cursor += target.Length;
    };
    pack(logon->Logon.LogonDomainName, domain);
    pack(logon->Logon.UserName, grant.username);
    pack(logon->Logon.Password, protectedPassword.value);
    LSA_HANDLE lsa{};
    NTSTATUS status = LsaConnectUntrusted(&lsa);
    ULONG package{};
    if (status == 0) {
        char name[] = "Negotiate"; LSA_STRING negotiate{9, 10, name};
        status = LsaLookupAuthenticationPackage(lsa, &negotiate, &package);
        LsaDeregisterLogonProcess(lsa);
    }
    if (status != 0) {
        SecureZeroMemory(buffer, total); CoTaskMemFree(buffer);
        return HRESULT_FROM_WIN32(LsaNtStatusToWinError(status));
    }
    output->ulAuthenticationPackage = package;
    output->clsidCredentialProvider = ProviderId;
    output->cbSerialization = static_cast<ULONG>(total);
    output->rgbSerialization = buffer;
    return S_OK;
}

class Credential final : public ICredentialProviderCredential {
    std::atomic<ULONG> refs{1};
public:
    Credential() { ++Objects; }
    ~Credential() { --Objects; }
    IFACEMETHODIMP QueryInterface(REFIID id, void** value) override {
        if (!value) return E_POINTER; *value = nullptr;
        if (id == IID_IUnknown || id == IID_ICredentialProviderCredential) { *value = static_cast<ICredentialProviderCredential*>(this); AddRef(); return S_OK; }
        return E_NOINTERFACE;
    }
    IFACEMETHODIMP_(ULONG) AddRef() override { return ++refs; }
    IFACEMETHODIMP_(ULONG) Release() override { const auto count = --refs; if (!count) delete this; return count; }
    IFACEMETHODIMP Advise(ICredentialProviderCredentialEvents*) override { return S_OK; }
    IFACEMETHODIMP UnAdvise() override { return S_OK; }
    IFACEMETHODIMP SetSelected(BOOL* autoLogon) override { if (!autoLogon) return E_POINTER; *autoLogon = FALSE; return S_OK; }
    IFACEMETHODIMP SetDeselected() override { return S_OK; }
    IFACEMETHODIMP GetFieldState(DWORD id, CREDENTIAL_PROVIDER_FIELD_STATE* state, CREDENTIAL_PROVIDER_FIELD_INTERACTIVE_STATE* interactive) override {
        if (id > 1 || !state || !interactive) return E_INVALIDARG;
        *state = id == 0 ? CPFS_DISPLAY_IN_BOTH : CPFS_DISPLAY_IN_SELECTED_TILE;
        *interactive = CPFIS_NONE; return S_OK;
    }
    IFACEMETHODIMP GetStringValue(DWORD id, PWSTR* value) override { return id > 1 ? E_INVALIDARG : SHStrDupW(id == 0 ? L"Platform Classroom" : L"Start assigned session", value); }
    IFACEMETHODIMP GetBitmapValue(DWORD, HBITMAP*) override { return E_NOTIMPL; }
    IFACEMETHODIMP GetCheckboxValue(DWORD, BOOL*, PWSTR*) override { return E_NOTIMPL; }
    IFACEMETHODIMP GetSubmitButtonValue(DWORD id, DWORD* adjacent) override { if (id != 1 || !adjacent) return E_INVALIDARG; *adjacent = 0; return S_OK; }
    IFACEMETHODIMP GetComboBoxValueCount(DWORD, DWORD*, DWORD*) override { return E_NOTIMPL; }
    IFACEMETHODIMP GetComboBoxValueAt(DWORD, DWORD, PWSTR*) override { return E_NOTIMPL; }
    IFACEMETHODIMP SetStringValue(DWORD, PCWSTR) override { return E_NOTIMPL; }
    IFACEMETHODIMP SetCheckboxValue(DWORD, BOOL) override { return E_NOTIMPL; }
    IFACEMETHODIMP SetComboBoxSelectedValue(DWORD, DWORD) override { return E_NOTIMPL; }
    IFACEMETHODIMP CommandLinkClicked(DWORD) override { return E_NOTIMPL; }
    IFACEMETHODIMP GetSerialization(CREDENTIAL_PROVIDER_GET_SERIALIZATION_RESPONSE* result,
        CREDENTIAL_PROVIDER_CREDENTIAL_SERIALIZATION* output, PWSTR* text, CREDENTIAL_PROVIDER_STATUS_ICON* icon) override {
        if (!result || !output || !text || !icon) return E_POINTER;
        *result = CPGSR_NO_CREDENTIAL_NOT_FINISHED; ZeroMemory(output, sizeof(*output)); *text = nullptr; *icon = CPSI_NONE;
        try {
            Grant grant; ReadGrant(grant); winrt::check_hresult(SerializeGrant(grant, output));
            *result = CPGSR_RETURN_CREDENTIAL_FINISHED; return S_OK;
        } catch (...) {
            *icon = CPSI_WARNING;
            SHStrDupW(L"The assigned classroom session is not ready. Contact your teacher.", text);
            return S_OK;
        }
    }
    IFACEMETHODIMP ReportResult(NTSTATUS, NTSTATUS, PWSTR* text, CREDENTIAL_PROVIDER_STATUS_ICON* icon) override {
        if (text) *text = nullptr; if (icon) *icon = CPSI_NONE; return S_OK;
    }
};

class Provider final : public ICredentialProvider {
    std::atomic<ULONG> refs{1};
public:
    Provider() { ++Objects; }
    ~Provider() { --Objects; }
    IFACEMETHODIMP QueryInterface(REFIID id, void** value) override {
        if (!value) return E_POINTER; *value = nullptr;
        if (id == IID_IUnknown || id == IID_ICredentialProvider) { *value = static_cast<ICredentialProvider*>(this); AddRef(); return S_OK; }
        return E_NOINTERFACE;
    }
    IFACEMETHODIMP_(ULONG) AddRef() override { return ++refs; }
    IFACEMETHODIMP_(ULONG) Release() override { const auto count = --refs; if (!count) delete this; return count; }
    IFACEMETHODIMP SetUsageScenario(CREDENTIAL_PROVIDER_USAGE_SCENARIO scenario, DWORD) override { return scenario == CPUS_LOGON ? S_OK : E_NOTIMPL; }
    IFACEMETHODIMP SetSerialization(const CREDENTIAL_PROVIDER_CREDENTIAL_SERIALIZATION*) override { return E_NOTIMPL; }
    IFACEMETHODIMP Advise(ICredentialProviderEvents*, UINT_PTR) override { return S_OK; }
    IFACEMETHODIMP UnAdvise() override { return S_OK; }
    IFACEMETHODIMP GetFieldDescriptorCount(DWORD* count) override { if (!count) return E_POINTER; *count = 2; return S_OK; }
    IFACEMETHODIMP GetFieldDescriptorAt(DWORD id, CREDENTIAL_PROVIDER_FIELD_DESCRIPTOR** value) override {
        if (id > 1 || !value) return E_INVALIDARG;
        *value = static_cast<CREDENTIAL_PROVIDER_FIELD_DESCRIPTOR*>(CoTaskMemAlloc(sizeof(**value)));
        if (!*value) return E_OUTOFMEMORY;
        ZeroMemory(*value, sizeof(**value)); (*value)->dwFieldID = id; (*value)->cpft = id == 0 ? CPFT_LARGE_TEXT : CPFT_SUBMIT_BUTTON;
        const auto result = SHStrDupW(id == 0 ? L"Platform Classroom" : L"Start assigned session", &(*value)->pszLabel);
        if (FAILED(result)) { CoTaskMemFree(*value); *value = nullptr; } return result;
    }
    IFACEMETHODIMP GetCredentialCount(DWORD* count, DWORD* selected, BOOL* autoLogon) override {
        if (!count || !selected || !autoLogon) return E_POINTER;
        *count = 1; *selected = CREDENTIAL_PROVIDER_NO_DEFAULT; *autoLogon = FALSE; return S_OK;
    }
    IFACEMETHODIMP GetCredentialAt(DWORD index, ICredentialProviderCredential** value) override {
        if (index || !value) return E_INVALIDARG; *value = new (std::nothrow) Credential(); return *value ? S_OK : E_OUTOFMEMORY;
    }
};
class Factory final : public IClassFactory {
    std::atomic<ULONG> refs{1};
public:
    Factory() { ++Objects; }
    ~Factory() { --Objects; }
    IFACEMETHODIMP QueryInterface(REFIID id, void** value) override {
        if (!value) return E_POINTER; *value = nullptr;
        if (id == IID_IUnknown || id == IID_IClassFactory) { *value = static_cast<IClassFactory*>(this); AddRef(); return S_OK; } return E_NOINTERFACE;
    }
    IFACEMETHODIMP_(ULONG) AddRef() override { return ++refs; }
    IFACEMETHODIMP_(ULONG) Release() override { const auto count = --refs; if (!count) delete this; return count; }
    IFACEMETHODIMP CreateInstance(IUnknown* outer, REFIID id, void** value) override {
        if (outer) return CLASS_E_NOAGGREGATION;
        auto* provider = new (std::nothrow) Provider(); if (!provider) return E_OUTOFMEMORY;
        const auto result = provider->QueryInterface(id, value); provider->Release(); return result;
    }
    IFACEMETHODIMP LockServer(BOOL lock) override { if (lock) ++Objects; else --Objects; return S_OK; }
};
}
extern "C" HRESULT __stdcall DllGetClassObject(REFCLSID clsid, REFIID id, void** value) {
    if (clsid != ProviderId) return CLASS_E_CLASSNOTAVAILABLE;
    auto* factory = new (std::nothrow) Factory(); if (!factory) return E_OUTOFMEMORY;
    const auto result = factory->QueryInterface(id, value); factory->Release(); return result;
}
extern "C" HRESULT __stdcall DllCanUnloadNow() { return Objects == 0 ? S_OK : S_FALSE; }
