using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;

namespace ZafiroAgent.Localization
{
    /// <summary>
    /// Язык агента в каждом запросе: сервер отвечает сообщениями на языке, который видит клиент
    /// (X-Agent-Locale — для запросов клуба, Accept-Language — для входа, пока клуб не известен).
    /// </summary>
    public sealed class LocaleHandler : DelegatingHandler
    {
        public LocaleHandler(HttpMessageHandler inner)
            : base(inner)
        {
        }

        protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancellationToken)
        {
            var locale = L.Current;
            if (!request.Headers.Contains(Header)) request.Headers.TryAddWithoutValidation(Header, locale);
            if (request.Headers.AcceptLanguage.Count == 0) request.Headers.TryAddWithoutValidation("Accept-Language", locale);
            return base.SendAsync(request, cancellationToken);
        }

        public const string Header = "X-Agent-Locale";
    }
}
