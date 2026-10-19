using System;
using System.IO;
using System.Net.Http;
using System.Threading.Tasks;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using ZafiroAgent.Services;

namespace ZafiroAgent.Infrastructure
{
    /// <summary>
    /// Картинка клуба с сервера Zafiro (логотип, картинка экрана входа): только HTTPS на хост Zafiro,
    /// без редиректов, с пределом размера; уменьшается при декодировании, чтобы не занять всю память ПК.
    /// </summary>
    public static class RemoteImage
    {
        private static HttpClient? _http;

        public static async Task<ImageSource?> DownloadAsync(string url, long maxBytes, int maxPixels)
        {
            if (!ZafiroApiClient.TryValidateHttpsEndpoint(url, out var uri)) return null;

            _http ??= ZafiroApiClient.CreateDirectHttpClient();
            using var response = await _http.GetAsync(uri, HttpCompletionOption.ResponseHeadersRead).ConfigureAwait(false);
            if (!response.IsSuccessStatusCode || response.Content.Headers.ContentLength > maxBytes) return null;

            var buffer = new MemoryStream();
            await using (var source = await response.Content.ReadAsStreamAsync().ConfigureAwait(false))
            {
                var chunk = new byte[81920];
                int read;
                while ((read = await source.ReadAsync(chunk).ConfigureAwait(false)) > 0)
                {
                    if (buffer.Length + read > maxBytes) return null;
                    buffer.Write(chunk, 0, read);
                }
            }

            // Размер читается до декодирования: огромная картинка не займёт всю память ПК.
            buffer.Position = 0;
            var frame = BitmapDecoder.Create(buffer, BitmapCreateOptions.DelayCreation | BitmapCreateOptions.IgnoreColorProfile, BitmapCacheOption.None).Frames[0];
            var (width, height) = (frame.PixelWidth, frame.PixelHeight);
            if (width <= 0 || height <= 0) return null;

            buffer.Position = 0;
            var image = new BitmapImage();
            image.BeginInit();
            image.CacheOption = BitmapCacheOption.OnLoad;
            image.CreateOptions = BitmapCreateOptions.IgnoreColorProfile;
            if (width >= height && width > maxPixels) image.DecodePixelWidth = maxPixels;
            else if (height > width && height > maxPixels) image.DecodePixelHeight = maxPixels;
            image.StreamSource = buffer;
            image.EndInit();
            image.Freeze();
            return image;
        }
    }
}
