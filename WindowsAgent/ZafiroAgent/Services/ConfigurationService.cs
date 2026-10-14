using System;
using System.IO;
using System.Text.Json;
using ZafiroAgent.Configuration;

namespace ZafiroAgent.Services
{
    public class ConfigurationService
    {
        // Heartbeat пишет конфиг из фонового потока, поэтому чтение и запись сериализуем.
        private static readonly object Gate = new();

        private readonly string _path;

        public ConfigurationService()
            : this(Path.Combine(Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData), "ZafiroAgent", "agent.config.json"))
        {
        }

        public ConfigurationService(string path)
        {
            _path = path;
            Directory.CreateDirectory(Path.GetDirectoryName(Path.GetFullPath(path))!);
        }

        public AgentConfig Load()
        {
            lock (Gate)
            {
                try
                {
                    if (!File.Exists(_path)) return new AgentConfig();
                    var json = File.ReadAllText(_path);
                    return JsonSerializer.Deserialize<AgentConfig>(json) ?? new AgentConfig();
                }
                catch
                {
                    return new AgentConfig();
                }
            }
        }

        public void Save(AgentConfig cfg)
        {
            lock (Gate)
            {
                // Сначала во временный файл, затем замена: при отключении питания старые настройки
                // остаются целыми, а не превращаются в пустой файл.
                var json = JsonSerializer.Serialize(cfg, new JsonSerializerOptions { WriteIndented = true });
                var temp = _path + ".tmp";
                File.WriteAllText(temp, json);
                File.Move(temp, _path, overwrite: true);
            }
        }

        // Читает актуальный конфиг непосредственно перед записью: не затирает чужие изменения (например, токен после выхода).
        public void Update(Action<AgentConfig> change)
        {
            lock (Gate)
            {
                var cfg = Load();
                change(cfg);
                Save(cfg);
            }
        }
    }
}
