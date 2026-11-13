<?php
/**
 * Invoice / booking summary template.
 * Receives $invoice (from the Invoice service's data()), with $invoice['engine'] for money().
 *
 * THE MARKUP IS TABLES WITH INLINE STYLES ON PURPOSE. The same HTML is handed to renderers that
 * read no stylesheet at all, so everything the document needs to look right is written on the
 * elements themselves, in plain hex colours and a font such a renderer carries.
 *
 * The `pcp-inv-*` classes are for the page only. The public stylesheet uses them to put the
 * summary in the site's own font and, in a dark colour scheme, to swap the inline colours for
 * the dark ones. A renderer that ignores stylesheets ignores them too.
 *
 * @var array $invoice
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render
 *   variables. This file is only ever include()d from inside a method, so these are
 *   function-scoped locals, not globals; the sniff cannot see the enclosing scope.
 */
if (!defined('ABSPATH')) {
    exit;
}
$b   = $invoice['booking'];
$eng = $invoice['engine'];
$pcp_money = static fn($v) => $eng->money((float) $v, $invoice);

// A stored datetime in the site's own date and time format, with translated month names.
$pcp_format = trim((string) \PlugixaCarParking\Core\Settings::get('date_format', 'Y-m-d') . ' ' . (string) \PlugixaCarParking\Core\Settings::get('time_format', 'H:i'));
$pcp_when   = static function ($value) use ($pcp_format): string {
    $value = (string) $value;
    if ('' === $value) {
        return '';
    }
    $formatted = mysql2date($pcp_format, $value);
    return is_string($formatted) && '' !== $formatted ? $formatted : $value;
};

$pcp_status = (string) ($b['payment_status'] ?? 'unpaid');
$pcp_status = '' !== $pcp_status ? $pcp_status : 'unpaid';
$pcp_label  = \PlugixaCarParking\Modules\Frontend\Labels::of(\PlugixaCarParking\Modules\Frontend\Labels::payment(), $pcp_status);
// White text on each of these passes AA. Paid is green, unpaid red, anything in between amber.
$pcp_badge  = 'paid' === $pcp_status ? '#2e7d32' : ('unpaid' === $pcp_status ? '#c62828' : '#8a5300');

/**
 * Rows to show in the totals between the discount and the total.
 *
 * Each row: array( 'label' => string, 'amount' => float ). Empty unless something adds one.
 *
 * @param array $rows    The rows.
 * @param array $booking The booking row.
 * @param array $invoice Everything the template renders.
 */
$pcp_rows = (array) apply_filters('plugixa_carparking_invoice_total_rows', array(), $b, $invoice);

/**
 * Lines to show under the booking reference at the top, such as a number the invoice is filed under.
 *
 * Each line: array( 'label' => string, 'value' => string ). Empty unless something adds one.
 *
 * @param array $lines   The lines.
 * @param array $booking The booking row.
 * @param array $invoice Everything the template renders.
 */
$pcp_head = (array) apply_filters('plugixa_carparking_invoice_header_lines', array(), $b, $invoice);
?>
<div class="pcp-invoice pcp-inv" style="width:100%;font-family:DejaVu Sans, Arial, sans-serif;color:#212121;font-size:13px;line-height:1.5;">
  <table class="pcp-inv-table" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <tr>
      <td style="vertical-align:top;padding:0;">
        <div class="pcp-inv-company" style="font-size:20px;font-weight:700;"><?php echo esc_html($invoice['company']); ?></div>
        <div class="pcp-inv-muted" style="color:#616161;"><?php esc_html_e('Parking booking invoice', 'plugixa-car-parking'); ?></div>
      </td>
      <td style="vertical-align:top;text-align:right;padding:0;">
        <div class="pcp-inv-ref" style="font-size:16px;font-weight:700;"><?php echo esc_html($b['booking_ref'] ?? ''); ?></div>
        <div class="pcp-inv-muted pcp-inv-date" style="color:#616161;"><?php echo esc_html($pcp_when($b['created_at'] ?? '')); ?></div>
<?php
foreach ($pcp_head as $pcp_line) {
    // Nothing at all is written when nothing adds a line, so the page is byte for byte as before.
    if (is_array($pcp_line) && '' !== (string) ($pcp_line['value'] ?? '')) {
        echo '        <div class="pcp-inv-muted" style="color:#616161;">' . esc_html((string) ($pcp_line['label'] ?? '')) . ' <strong style="color:#212121;">' . esc_html((string) $pcp_line['value']) . '</strong></div>' . "\n";
    }
}
?>
        <div style="margin-top:6px;">
          <span class="pcp-inv-badge" style="display:inline-block;padding:2px 10px;border-radius:10px;color:#ffffff;background:<?php echo esc_attr($pcp_badge); ?>;font-size:11px;font-weight:700;"><?php echo esc_html($pcp_label); ?></span>
        </div>
      </td>
    </tr>
  </table>
<?php
/**
 * Fires under the invoice's heading, for a module to show something of its own there, such as a
 * code the customer shows on arrival. Whatever is printed must be escaped by whoever prints it.
 * Nothing is written when nothing listens, so the page is byte for byte as before.
 *
 * @param array $booking The booking row.
 * @param array $invoice Everything the template renders.
 */
do_action('plugixa_carparking_invoice_after_header', $b, $invoice);
?>

  <table class="pcp-inv-table" style="width:100%;border-collapse:collapse;margin-bottom:20px;">
    <tr>
      <td class="pcp-inv-party" style="vertical-align:top;width:50%;padding:0 12px 0 0;">
        <div class="pcp-inv-muted pcp-inv-caption" style="color:#616161;text-transform:uppercase;font-size:11px;font-weight:700;margin-bottom:4px;"><?php esc_html_e('Customer', 'plugixa-car-parking'); ?></div>
        <div><strong><?php echo esc_html($b['customer_name'] ?? '—'); ?></strong></div>
        <div><?php echo esc_html($b['customer_email'] ?? ''); ?></div>
        <div><?php echo esc_html($b['customer_phone'] ?? ''); ?></div>
        <?php if (!empty($b['license_plate'])) : ?>
          <div><?php esc_html_e('Plate:', 'plugixa-car-parking'); ?> <?php echo esc_html($b['license_plate']); ?></div>
        <?php endif; ?>
        <?php foreach ((array) ($invoice['custom_fields'] ?? array()) as $pcp_answer) : ?>
          <div class="pcp-inv-answer"><?php echo esc_html((string) $pcp_answer['label']); ?>: <?php echo esc_html((string) $pcp_answer['value']); ?></div>
        <?php endforeach; ?>
      </td>
      <td class="pcp-inv-party" style="vertical-align:top;width:50%;padding:0;">
        <div class="pcp-inv-muted pcp-inv-caption" style="color:#616161;text-transform:uppercase;font-size:11px;font-weight:700;margin-bottom:4px;"><?php esc_html_e('Booking', 'plugixa-car-parking'); ?></div>
        <div><strong><?php echo esc_html($invoice['location_name']); ?></strong><?php echo '' !== (string) $invoice['space_type_name'] ? ' — ' . esc_html($invoice['space_type_name']) : ''; ?></div>
        <div><?php esc_html_e('Entry:', 'plugixa-car-parking'); ?> <?php echo esc_html($pcp_when($b['entry_datetime'] ?? '')); ?></div>
        <div><?php esc_html_e('Exit:', 'plugixa-car-parking'); ?> <?php echo esc_html($pcp_when($b['exit_datetime'] ?? '')); ?></div>
        <div><?php esc_html_e('Quantity:', 'plugixa-car-parking'); ?> <?php echo (int) ($b['quantity'] ?? 1); ?></div>
      </td>
    </tr>
  </table>

  <table class="pcp-inv-table pcp-inv-items" style="width:100%;border-collapse:collapse;margin-bottom:16px;">
    <thead>
      <tr class="pcp-inv-head" style="background:#f5f5f5;">
        <th scope="col" class="pcp-inv-cell" style="text-align:left;padding:8px;border-bottom:1px solid #e0e0e0;"><?php esc_html_e('Item', 'plugixa-car-parking'); ?></th>
        <th scope="col" class="pcp-inv-cell" style="text-align:center;padding:8px;border-bottom:1px solid #e0e0e0;"><?php esc_html_e('Qty', 'plugixa-car-parking'); ?></th>
        <th scope="col" class="pcp-inv-cell" style="text-align:right;padding:8px;border-bottom:1px solid #e0e0e0;"><?php esc_html_e('Unit', 'plugixa-car-parking'); ?></th>
        <th scope="col" class="pcp-inv-cell" style="text-align:right;padding:8px;border-bottom:1px solid #e0e0e0;"><?php esc_html_e('Total', 'plugixa-car-parking'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($invoice['items'] as $it) : ?>
        <tr>
          <?php // On one line: a renderer that keeps white space would otherwise print the indentation. ?>
          <td class="pcp-inv-cell" style="padding:8px;border-bottom:1px solid #eeeeee;"><?php echo esc_html($it['label']); ?><?php foreach ((array) ($it['adjustments'] ?? array()) as $pcp_adjustment) : ?><div class="pcp-inv-muted pcp-inv-adjustment" style="color:#616161;font-size:12px;padding-left:12px;"><?php echo esc_html($eng->adjustment((array) $pcp_adjustment, $invoice)); ?></div><?php endforeach; ?></td>
          <td class="pcp-inv-cell" style="padding:8px;border-bottom:1px solid #eeeeee;text-align:center;"><?php echo esc_html(rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.')); ?></td>
          <td class="pcp-inv-cell" style="padding:8px;border-bottom:1px solid #eeeeee;text-align:right;"><?php echo esc_html($pcp_money($it['unit_price'])); ?></td>
          <td class="pcp-inv-cell" style="padding:8px;border-bottom:1px solid #eeeeee;text-align:right;"><?php echo esc_html($pcp_money($it['total'])); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <table class="pcp-inv-table" style="width:100%;border-collapse:collapse;">
    <tr>
      <td class="pcp-inv-gap" style="width:55%;padding:0;"></td>
      <td style="width:45%;padding:0;">
        <table class="pcp-inv-table pcp-inv-totals" style="width:100%;border-collapse:collapse;">
          <tr><td class="pcp-inv-muted" style="padding:4px 8px;color:#616161;"><?php esc_html_e('Subtotal', 'plugixa-car-parking'); ?></td><td style="padding:4px 8px;text-align:right;"><?php echo esc_html($pcp_money($b['subtotal'] ?? 0)); ?></td></tr>
          <?php if ((float) ($b['discount_total'] ?? 0) > 0) : ?>
            <tr><td class="pcp-inv-muted" style="padding:4px 8px;color:#616161;"><?php esc_html_e('Discount', 'plugixa-car-parking'); ?></td><td style="padding:4px 8px;text-align:right;">−<?php echo esc_html($pcp_money($b['discount_total'])); ?></td></tr>
          <?php endif; ?>
          <?php foreach ($pcp_rows as $pcp_row) : ?>
            <?php if (!is_array($pcp_row) || !isset($pcp_row['label'], $pcp_row['amount']) || !is_numeric($pcp_row['amount'])) { continue; } ?>
            <tr><td class="pcp-inv-muted" style="padding:4px 8px;color:#616161;"><?php echo esc_html((string) $pcp_row['label']); ?></td><td style="padding:4px 8px;text-align:right;"><?php echo esc_html($pcp_money($pcp_row['amount'])); ?></td></tr>
          <?php endforeach; ?>
          <tr><td class="pcp-inv-total" style="padding:8px;font-weight:700;font-size:15px;border-top:2px solid #212121;"><?php esc_html_e('Total', 'plugixa-car-parking'); ?></td><td class="pcp-inv-total" style="padding:8px;text-align:right;font-weight:700;font-size:15px;border-top:2px solid #212121;"><?php echo esc_html($pcp_money($invoice['total'])); ?></td></tr>
          <?php if ($invoice['paid'] > 0) : ?>
            <tr><td class="pcp-inv-muted" style="padding:4px 8px;color:#616161;"><?php esc_html_e('Paid', 'plugixa-car-parking'); ?></td><td style="padding:4px 8px;text-align:right;"><?php echo esc_html($pcp_money($invoice['paid'])); ?></td></tr>
            <tr><td style="padding:4px 8px;font-weight:700;"><?php esc_html_e('Balance due', 'plugixa-car-parking'); ?></td><td style="padding:4px 8px;text-align:right;font-weight:700;"><?php echo esc_html($pcp_money($invoice['balance'])); ?></td></tr>
          <?php endif; ?>
        </table>
      </td>
    </tr>
  </table>
</div>
