using System;
using System.Linq;
using System.ServiceProcess;
using ZafiroAgent.Service;

namespace ZafiroAgent
{
    /// <summary>Один exe на два режима: агент на экране клиента и служба Windows (--service).</summary>
    public static class Program
    {
        [STAThread]
        public static int Main(string[] args)
        {
            if (args.Contains("--service"))
            {
                ServiceBase.Run(new AgentService());
                return 0;
            }

            var app = new App();
            app.InitializeComponent();
            return app.Run();
        }
    }
}
