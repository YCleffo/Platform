const alertmanager = process.env.ALERTMANAGER_URL ?? 'http://127.0.0.1:9093';
const sink = process.env.ALERT_SINK_URL ?? 'http://127.0.0.1:8085';
const timeoutSeconds = Number.parseInt(process.env.ALERT_DELIVERY_TIMEOUT_SECONDS ?? '45', 10);
const alertName = `PlatformDeliveryProbe${Date.now()}`;
const startsAt = new Date();
const endsAt = new Date(startsAt.getTime() + 120_000);

const alert = {
  labels: { alertname: alertName, severity: 'info', plane: 'observability' },
  annotations: {
    summary: 'Alert delivery probe',
    description: 'Verifies Alertmanager webhook delivery to the configured receiver.',
  },
  startsAt: startsAt.toISOString(),
  endsAt: endsAt.toISOString(),
};

const response = await fetch(`${alertmanager}/api/v2/alerts`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify([alert]),
  signal: AbortSignal.timeout(15_000),
});
if (!response.ok) {
  throw new Error(`Alertmanager rejected the probe: HTTP ${response.status} ${await response.text()}`);
}

const deadline = Date.now() + timeoutSeconds * 1000;
let received = false;
while (Date.now() < deadline) {
  await new Promise((resolve) => setTimeout(resolve, 1000));
  try {
    const result = await fetch(`${sink}/alerts`, { signal: AbortSignal.timeout(5_000) });
    if (!result.ok) continue;
    const body = await result.json();
    const batches = Array.isArray(body.alerts) ? body.alerts : [];
    received = batches.some((batch) =>
      Array.isArray(batch.alerts) && batch.alerts.some((item) => item?.labels?.alertname === alertName),
    );
    if (received) break;
  } catch {
    // The receiver may still be starting; the probe remains bounded by the deadline.
  }
}

if (!received) {
  console.error(`FAIL: ${alertName} was not delivered to ${sink} within ${timeoutSeconds}s.`);
  process.exitCode = 1;
} else {
  console.log(`PASS: ${alertName} delivered to ${sink}.`);
}
