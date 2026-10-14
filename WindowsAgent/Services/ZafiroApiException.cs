using System;
using ZafiroAgent.Models;

namespace ZafiroAgent.Services
{
    public sealed class ZafiroApiException : Exception
    {
        public ApiFailure Failure { get; }

        public ZafiroApiException(ApiFailure failure, string message) : base(message)
        {
            Failure = failure;
        }
    }
}
