using System.Net;
using System.Text;

namespace ZafiroAgent.Tests;

/// <summary>Mock HTTP-слой: тесты никогда не обращаются к production API.</summary>
internal sealed class StubHandler : HttpMessageHandler
{
    private readonly Func<HttpRequestMessage, string?, HttpResponseMessage> _respond;

    public List<RecordedRequest> Requests { get; } = new();

    public RecordedRequest[] Snapshot()
    {
        lock (Requests) return Requests.ToArray();
    }

    public StubHandler(Func<HttpRequestMessage, string?, HttpResponseMessage> respond)
    {
        _respond = respond;
    }

    protected override async Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
    {
        var body = request.Content is null ? null : await request.Content.ReadAsStringAsync(cancellationToken);
        lock (Requests) Requests.Add(new RecordedRequest(request.Method, request.RequestUri!, request.Headers.Authorization?.ToString(), body));
        return _respond(request, body);
    }

    public static HttpResponseMessage Json(HttpStatusCode status, string json) =>
        new(status) { Content = new StringContent(json, Encoding.UTF8, "application/json") };
}

internal sealed record RecordedRequest(HttpMethod Method, Uri Url, string? Authorization, string? Body);
