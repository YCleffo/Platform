import type { InvoiceLine, Refund, RefundItemInput } from '@/shared/types';

export function remainingRefundLines(lines: InvoiceLine[], refunds: Refund[]) {
  return lines.map(line => {
    const reserved = refunds.filter(refund => refund.status !== 'canceled')
      .flatMap(refund => refund.items)
      .filter(item => item.invoice_line_id === line.id)
      .reduce((sum, item) => sum + item.quantity, 0);
    return { ...line, remaining: Math.max(0, line.quantity - reserved) };
  });
}

export function refundSelection(lines: ReturnType<typeof remainingRefundLines>, quantities: Record<string, number>, ceiling: number): RefundItemInput[] {
  const items: RefundItemInput[] = [];
  let total = 0;
  for (const line of lines) {
    const quantity = Number(quantities[line.id] ?? 0);
    if (!Number.isSafeInteger(quantity) || quantity < 0 || quantity > line.remaining) throw new Error('Invalid refund quantity');
    if (quantity === 0) continue;
    total += quantity * line.unit_amount_minor;
    if (!Number.isSafeInteger(total) || total > ceiling) throw new Error('Refund exceeds available balance');
    items.push({ invoice_line_id: line.id, quantity, unit_amount_minor: line.unit_amount_minor });
  }
  if (total <= 0 || items.length === 0) throw new Error('Select refund items');
  return items;
}
