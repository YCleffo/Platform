using System.Diagnostics;

namespace Platform.Agent;

/// <summary>A bounded shared rate budget; parallel parts cannot each consume the full configured rate.</summary>
internal sealed class TransferBandwidth(long bytesPerSecond)
{
    private readonly object _gate = new();
    private long _next;

    public async ValueTask WaitAsync(int bytes, CancellationToken cancellation)
    {
        if (bytesPerSecond == 0) return;
        long until;
        lock (_gate)
        {
            var now = Stopwatch.GetTimestamp();
            _next = Math.Max(_next, now) + (long)Math.Ceiling(bytes * (double)Stopwatch.Frequency / bytesPerSecond);
            until = _next;
        }
        var remaining = Stopwatch.GetElapsedTime(Stopwatch.GetTimestamp(), until);
        if (remaining > TimeSpan.Zero) await Task.Delay(remaining, cancellation);
    }
}

/// <summary>Never reads past one signed part and never owns the caller's file stream.</summary>
internal sealed class TransferReadStream : Stream
{
    private readonly Stream source;
    private readonly long length;
    private readonly TransferBandwidth? bandwidth;
    private long _remaining;

    public TransferReadStream(Stream source, long length, TransferBandwidth? bandwidth = null)
    {
        this.source = source;
        this.length = length;
        this.bandwidth = bandwidth;
        _remaining = length;
    }
    public override bool CanRead => true;
    public override bool CanSeek => false;
    public override bool CanWrite => false;
    public override long Length => length;
    public override long Position { get => length - _remaining; set => throw new NotSupportedException(); }
    public override int Read(byte[] buffer, int offset, int count)
    {
        var read = source.Read(buffer, offset, (int)Math.Min(count, _remaining));
        _remaining -= read;
        bandwidth?.WaitAsync(read, CancellationToken.None).AsTask().GetAwaiter().GetResult();
        return read;
    }
    public override async ValueTask<int> ReadAsync(Memory<byte> buffer, CancellationToken cancellationToken = default)
    {
        var read = await source.ReadAsync(buffer[..(int)Math.Min(buffer.Length, _remaining)], cancellationToken);
        _remaining -= read;
        if (bandwidth is not null) await bandwidth.WaitAsync(read, cancellationToken);
        return read;
    }
    public override Task<int> ReadAsync(byte[] buffer, int offset, int count, CancellationToken cancellationToken) =>
        ReadAsync(buffer.AsMemory(offset, count), cancellationToken).AsTask();
    public override long Seek(long offset, SeekOrigin origin) => throw new NotSupportedException();
    public override void SetLength(long value) => throw new NotSupportedException();
    public override void Write(byte[] buffer, int offset, int count) => throw new NotSupportedException();
    public override void Flush() { }
}
