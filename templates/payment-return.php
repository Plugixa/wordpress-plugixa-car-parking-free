<?php
/**
 * What the booking page shows a customer who has come back from paying online.
 *
 * @var string $state     'paid', 'pending' (said to be paid, not recorded as paid yet), 'no_space'
 *                        (paid after the hold ran out and the space had gone) or 'failed'.
 * @var string $reference The booking's reference, or '' when the address named no booking.
 * @var string $standing  Where a booking whose payment failed now stands: 'unpaid', 'paid',
 *                        'cancelled' or 'expired'. '' when the address named no booking.
 * @var string $restart   The booking form's address, without the payment's arguments, or '' when
 *                        the site has no page for it and the button is left out.
 * @var string $style     Custom properties for the form's own colour, or ''.
 * @var string $design    The look the page is drawn in: classic, split or minimal.
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

$screens = array(
    'paid'    => array(
        'title' => __('Payment received', 'plugixa-car-parking'),
        'lead'  => __('Thank you. Your payment has been received.', 'plugixa-car-parking'),
        'again' => __('Make another booking', 'plugixa-car-parking'),
    ),
    'pending' => array(
        'title' => __('Confirming your payment', 'plugixa-car-parking'),
        'lead'  => __('We have not had confirmation of your payment yet. This can take a few minutes; there is no need to pay again.', 'plugixa-car-parking'),
        'again' => __('Back to the booking form', 'plugixa-car-parking'),
    ),
    'no_space' => array(
        'title' => __('Your payment arrived too late to hold the space', 'plugixa-car-parking'),
        'lead'  => __('We received your payment, but the booking had run out and its space has since been taken. We have been told and will contact you about a refund.', 'plugixa-car-parking'),
        'again' => __('Start a new booking', 'plugixa-car-parking'),
    ),
    'failed'  => array(
        'title' => __('Payment not completed', 'plugixa-car-parking'),
        'lead'  => __('The payment was cancelled or did not go through, and nothing has been charged.', 'plugixa-car-parking'),
        'again' => __('Start a new booking', 'plugixa-car-parking'),
    ),
);

$screen = $screens[$state] ?? $screens['failed'];

/*
 * A failure is an alert, read out at once. The other two are a status. The page
 * has just loaded either way, so the heading carries the news for everyone.
 */
?>
<div class="pcp-wizard-shell">
<div class="pcp-ui pcp-wizard pcp-return pcp-return-<?php echo esc_attr($state); ?>" data-pcp-design="<?php echo esc_attr($design); ?>"<?php echo '' !== $style ? ' style="' . esc_attr($style) . '"' : ''; ?>>
    <div class="pcp-success" role="<?php echo in_array($state, array('failed', 'no_space'), true) ? 'alert' : 'status'; ?>">
        <div class="pcp-success-icon pcp-success-icon-<?php echo esc_attr($state); ?>" aria-hidden="true"></div>
        <h2 class="pcp-success-title"><?php echo esc_html($screen['title']); ?></h2>
        <p class="pcp-success-lead"><?php echo esc_html($screen['lead']); ?></p>
        <?php if ('' !== $reference) : ?>
            <p class="pcp-success-ref">
                <span class="pcp-success-ref-label"><?php esc_html_e('Your reference', 'plugixa-car-parking'); ?></span>
                <strong class="pcp-success-ref-value"><?php echo esc_html($reference); ?></strong>
            </p>
        <?php endif; ?>
        <?php
        // What to do next depends on the booking, not on the payment: one that is cancelled or
        // already paid has nothing left to pay.
        $notes = array(
            'unpaid'    => __('Your booking is saved but has not been paid. You can pay for it from your booking summary.', 'plugixa-car-parking'),
            'paid'      => __('Your booking is already paid in full, so there is nothing more to pay.', 'plugixa-car-parking'),
            'cancelled' => __('This booking has been cancelled, so there is nothing to pay. If you still need a space, please make a new booking.', 'plugixa-car-parking'),
            'expired'   => __('This booking was not paid in time and its space is no longer held. If you still need a space, please make a new booking.', 'plugixa-car-parking'),
        );
        ?>
        <?php if ('failed' === $state && '' !== $reference && isset($notes[$standing])) : ?>
            <p class="pcp-alert pcp-alert-info pcp-return-note pcp-return-note-<?php echo esc_attr($standing); ?>"><?php echo esc_html($notes[$standing]); ?></p>
        <?php endif; ?>
        <?php if ('' !== $restart) : ?>
        <div class="pcp-success-actions">
            <a class="pcp-btn pcp-btn-<?php echo 'paid' === $state ? 'secondary' : 'primary'; ?>" href="<?php echo esc_url($restart); ?>"><?php echo esc_html($screen['again']); ?></a>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
<?php
