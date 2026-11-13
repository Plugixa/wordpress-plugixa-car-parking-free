<?php
/**
 * Booking summary template.
 *
 * @var string $summary_html The booking's invoice, as HTML.
 * @var array  $actions      Further action links: each has a url and a label.
 * @var bool   $unpaid       Whether anything is still owed.
 * @var array  $gateways     The ways to pay now: each has an id, a label and whether it is paid online.
 * @var string $design       The look the page is drawn in: classic, split or minimal.
 *
 * @package PlugixaCarParking
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render
 *   variables. This file is only ever include()d from inside a method, so these are
 *   function-scoped locals, not globals; the sniff cannot see the enclosing scope.
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * The empty, hidden `data-pcp-slot` container is where a script that extends the
 * page puts what it adds to the payment box. See assets/frontend/js/core.js.
 */
?>
<div class="pcp-ui pcp-summary" data-pcp-design="<?php echo esc_attr($design); ?>" data-pcp-summary>
    <div class="pcp-card pcp-summary-doc">
        <?php echo wp_kses($summary_html, \PlugixaCarParking\Modules\Frontend\Kses::allowed_html()); ?>
    </div>
    <div class="pcp-summary-actions no-print">
        <button type="button" class="pcp-btn pcp-btn-secondary pcp-print-btn"><?php esc_html_e('Print', 'plugixa-car-parking'); ?></button>
        <?php foreach ($actions as $summary_action) : ?>
            <?php if (empty($summary_action['url']) || empty($summary_action['label'])) { continue; } ?>
            <a class="pcp-btn pcp-btn-secondary" href="<?php echo esc_url($summary_action['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($summary_action['label']); ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($unpaid && !empty($gateways)) : ?>
        <div class="pcp-card pcp-summary-pay no-print">
            <h3 class="pcp-h"><?php esc_html_e('Pay now', 'plugixa-car-parking'); ?></h3>
            <div class="pcp-slot" data-pcp-slot="payment" hidden></div>
            <div class="pcp-pay-buttons">
                <?php foreach ($gateways as $gw) : ?>
                    <button type="button" class="pcp-btn pcp-btn-primary pcp-pay-btn" data-gateway="<?php echo esc_attr($gw['id']); ?>" data-online="<?php echo !empty($gw['online']) ? '1' : '0'; ?>"><?php echo esc_html($gw['label'] ?? $gw['id']); ?></button>
                <?php endforeach; ?>
            </div>
            <div class="pcp-pay-msg"></div>
        </div>
    <?php endif; ?>
</div>
<?php
