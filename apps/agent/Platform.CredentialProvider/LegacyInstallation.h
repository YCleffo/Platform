#pragma once
#include <string_view>

// Existing managed accounts retain their Windows identity during an upgrade.
inline bool IsManagedAccountName(std::wstring_view name) {
    return name.starts_with(L"platform_") || name.starts_with(L"k1_std_");
}
