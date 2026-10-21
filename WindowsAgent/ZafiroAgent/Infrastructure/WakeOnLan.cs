using System;
using System.Collections.Generic;
using System.Globalization;
using System.Net;
using System.Net.Sockets;
using ZafiroAgent.Services;

namespace ZafiroAgent.Infrastructure
{
    /// <summary>
    /// Включение ПК клуба по сети: «магический пакет» (6 байт 0xFF и 16 раз MAC) широковещательно
    /// на порты 9 и 7. Выключенный ПК слушает его сетевой картой, если в BIOS включён Wake-on-LAN.
    /// </summary>
    public static class WakeOnLan
    {
        public static byte[] MagicPacket(string mac)
        {
            var bytes = new byte[6];
            var parts = mac.Split(':', '-');
            if (parts.Length != 6) throw new FormatException("MAC address must have six bytes.");
            for (var i = 0; i < 6; i++) bytes[i] = byte.Parse(parts[i], NumberStyles.HexNumber, CultureInfo.InvariantCulture);

            var packet = new byte[6 + 16 * 6];
            for (var i = 0; i < 6; i++) packet[i] = 0xFF;
            for (var i = 0; i < 16; i++) Buffer.BlockCopy(bytes, 0, packet, 6 + i * 6, 6);
            return packet;
        }

        public static void Send(IReadOnlyCollection<string> macs)
        {
            if (macs.Count == 0) return;
            try
            {
                using var client = new UdpClient { EnableBroadcast = true };
                foreach (var mac in macs)
                {
                    var packet = MagicPacket(mac);
                    foreach (var port in new[] { 9, 7 }) client.Send(packet, packet.Length, new IPEndPoint(IPAddress.Broadcast, port));
                }
                SafeLog.Write("[Wake] magic packet sent to " + macs.Count + " PC(s)");
            }
            catch (Exception ex) when (ex is SocketException or FormatException or ObjectDisposedException)
            {
                SafeLog.Write("[Wake] could not send: " + ex.Message);
            }
        }
    }
}
