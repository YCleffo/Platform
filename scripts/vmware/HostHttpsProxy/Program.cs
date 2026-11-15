using System.Net;
using System.Net.NetworkInformation;
using System.Security.Cryptography.X509Certificates;

// A local development bridge only. The backend stays bound to host loopback.
var listen = IPAddress.Parse(Environment.GetEnvironmentVariable("PLATFORM_PROXY_LISTEN") ?? throw new InvalidOperationException("Missing VMware adapter address."));
var adapter = Environment.GetEnvironmentVariable("PLATFORM_PROXY_ADAPTER") ?? "";
var network = NetworkInterface.GetAllNetworkInterfaces().SingleOrDefault(item => item.Name == adapter
    && item.Description.Contains("VMware", StringComparison.OrdinalIgnoreCase)
    && item.GetIPProperties().UnicastAddresses.Any(address => address.Address.Equals(listen)));
if (network is null || listen.AddressFamily != System.Net.Sockets.AddressFamily.InterNetwork || IPAddress.IsLoopback(listen))
    throw new InvalidOperationException("The listener must use an explicitly selected VMware IPv4 adapter address.");
var backend = new Uri(Environment.GetEnvironmentVariable("PLATFORM_PROXY_BACKEND") ?? "http://127.0.0.1:8080");
if (backend.Scheme != "http" || !backend.IsLoopback || backend.UserInfo.Length != 0 || backend.AbsolutePath != "/" || backend.Query.Length != 0 || backend.Fragment.Length != 0)
    throw new InvalidOperationException("The proxy backend must be a loopback HTTP origin.");
var port = int.Parse(Environment.GetEnvironmentVariable("PLATFORM_PROXY_PORT") ?? "8443");
if (port is < 1024 or > 65535) throw new InvalidOperationException("Select a local development port between 1024 and 65535.");
using var certificate = X509CertificateLoader.LoadPkcs12FromFile(
    Environment.GetEnvironmentVariable("PLATFORM_PROXY_PFX") ?? throw new InvalidOperationException("Missing server certificate."),
    Environment.GetEnvironmentVariable("PLATFORM_PROXY_PFX_PASSWORD"), X509KeyStorageFlags.EphemeralKeySet);
if (!certificate.HasPrivateKey || certificate.NotAfter.ToUniversalTime() <= DateTime.UtcNow) throw new InvalidOperationException("The TLS certificate is expired or has no private key.");
Environment.SetEnvironmentVariable("PLATFORM_PROXY_PFX_PASSWORD", null);
var builder = WebApplication.CreateBuilder(args);
builder.Logging.ClearProviders(); // Request URLs and auth/ticket values must not enter development logs.
builder.WebHost.ConfigureKestrel(options => options.Listen(listen, port, endpoint => endpoint.UseHttps(certificate)));
using var client = new HttpClient(new SocketsHttpHandler { AllowAutoRedirect = false, UseCookies = false, AutomaticDecompression = DecompressionMethods.None })
{ Timeout = TimeSpan.FromMinutes(5) };
var hop = new HashSet<string>(StringComparer.OrdinalIgnoreCase)
{ "Host", "Connection", "Keep-Alive", "Proxy-Authenticate", "Proxy-Authorization", "TE", "Trailer", "Transfer-Encoding", "Upgrade", "Forwarded", "X-Forwarded-For", "X-Forwarded-Host", "X-Forwarded-Proto" };
var app = builder.Build();
app.Run(async context =>
{
    using var request = new HttpRequestMessage(new HttpMethod(context.Request.Method),
        backend.GetLeftPart(UriPartial.Authority) + context.Request.PathBase + context.Request.Path + context.Request.QueryString);
    if (context.Request.ContentLength is > 0 || context.Request.Headers.ContainsKey("Transfer-Encoding"))
        request.Content = new StreamContent(context.Request.Body);
    foreach (var header in context.Request.Headers)
        if (!hop.Contains(header.Key) && !request.Headers.TryAddWithoutValidation(header.Key, header.Value.ToArray()))
            request.Content?.Headers.TryAddWithoutValidation(header.Key, header.Value.ToArray());
    try
    {
        using var response = await client.SendAsync(request, HttpCompletionOption.ResponseHeadersRead, context.RequestAborted);
        context.Response.StatusCode = (int)response.StatusCode;
        foreach (var header in response.Headers.Concat(response.Content.Headers))
            if (!hop.Contains(header.Key)) context.Response.Headers[header.Key] = header.Value.ToArray();
        await response.Content.CopyToAsync(context.Response.Body, context.RequestAborted);
    }
    catch (HttpRequestException) when (!context.Response.HasStarted) { context.Response.StatusCode = 502; }
    catch (OperationCanceledException) when (!context.Response.HasStarted) { context.Response.StatusCode = 504; }
});
await app.RunAsync();
