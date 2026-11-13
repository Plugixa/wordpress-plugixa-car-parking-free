<?php
/**
 * Booking wizard template.
 *
 * @var array  $locations         List of [id, name, inventory_mode, default_billing_type, currency].
 * @var int    $default_location  Pre-selected location id.
 * @var string $style             Custom properties for the form's own colour, or ''.
 * @var array  $flow              The screens the form shows, in order: each a key and a label.
 * @var array|null $config        The form's own settings for the script, or null for none.
 * @var string $location_notice   An editor-only note about the page's location, or ''.
 * @var string $design            The look the form is drawn in: classic, split or minimal.
 *
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- template-scope render
 *   variables. This file is only ever include()d from inside a method, so these are
 *   function-scoped locals, not globals; the sniff cannot see the enclosing scope.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (empty($locations)) {
    echo '<div class="pcp-ui pcp-wizard pcp-wizard-empty"><p class="pcp-empty">' . esc_html__('No parking locations are available yet.', 'plugixa-car-parking') . '</p></div>';
    return;
}

/*
 * .pcp-wizard-shell exists only to be the CSS container. A container query can
 * style a container's DESCENDANTS but never the container element itself, so
 * with container-type on .pcp-wizard its own padding could not respond to how
 * much room the theme actually gave the widget. The shell carries no styling of
 * its own beyond that.
 *
 * Nothing below has an id: a page can hold the form twice, and the block is
 * rendered again for every preview. The script gives a field's message the id
 * that aria-describedby needs, at the moment there is a message. Each label
 * wraps its control, so the two are tied together without one.
 *
 * The empty, hidden `data-pcp-slot` containers are where a script that extends
 * the form puts what it adds. See assets/frontend/js/core.js.
 */
?>
<div class="pcp-wizard-shell">
<div class="pcp-ui pcp-wizard" data-pcp-design="<?php echo esc_attr($design); ?>" data-default-location="<?php echo esc_attr((string) $default_location); ?>"<?php echo null !== $config ? ' data-pcp-config="' . esc_attr((string) wp_json_encode($config)) . '"' : ''; ?><?php echo '' !== $style ? ' style="' . esc_attr($style) . '"' : ''; ?>>
<?php if ('' !== $location_notice) : ?>
    <p class="pcp-alert pcp-alert-info pcp-location-notice"><?php echo esc_html($location_notice); ?></p>
<?php endif; ?>

    <header class="pcp-header">
        <span class="pcp-header-badge" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false">
                <rect x="3" y="3" width="18" height="18" rx="5"/>
                <path d="M9.5 17V7.5h3.25a2.75 2.75 0 0 1 0 5.5H9.5"/>
            </svg>
        </span>
        <div class="pcp-header-text">
            <h2 class="pcp-title"><?php esc_html_e('Book your parking', 'plugixa-car-parking'); ?></h2>
            <p class="pcp-subtitle"><?php esc_html_e('Reserve a space in a few steps.', 'plugixa-car-parking'); ?></p>
        </div>
    </header>

    <nav class="pcp-stepper" aria-label="<?php esc_attr_e('Booking progress', 'plugixa-car-parking'); ?>" style="--pcp-progress: 0">
        <?php
        /*
         * "Step 1 of 4" and a bar under it. Only the minimal design shows these; the others
         * draw the same progress from the numbered list. The script keeps both up to date.
         */
        ?>
        <p class="pcp-progress-text" aria-hidden="true"><?php echo esc_html(sprintf(/* translators: 1: the current step's number. 2: how many steps there are. */ __('Step %1$s of %2$s', 'plugixa-car-parking'), 1, count($flow))); ?></p>
        <div class="pcp-progress" aria-hidden="true"><span class="pcp-progress-bar"></span></div>
        <?php
        /*
         * One entry for each screen the form shows, in the order it shows them. The script
         * reads the order from `data-step`, so this list is what the customer walks through.
         */
        ?>
        <ol class="pcp-steps">
            <?php foreach ($flow as $position => $flow_step) : ?>
                <li class="pcp-step<?php echo 0 === $position ? ' is-active' : ''; ?>" data-step="<?php echo esc_attr($flow_step['key']); ?>"<?php echo 0 === $position ? ' aria-current="step"' : ''; ?>><span class="pcp-step-marker" aria-hidden="true"><?php echo (int) $position + 1; ?></span><span class="pcp-step-text"><span class="pcp-step-label"><?php echo esc_html($flow_step['label']); ?></span><span class="pcp-step-desc"><?php echo esc_html($flow_step['desc']); ?></span></span></li>
            <?php endforeach; ?>
        </ol>
    </nav>

    <div class="pcp-body">
    <div class="pcp-panels">

    <div class="pcp-alert pcp-alert-error pcp-error" role="alert" hidden></div>

    <!-- Step 1: dates & location -->
    <section class="pcp-panel is-active" data-panel="1">
        <h3 class="pcp-h"><?php esc_html_e('When do you need parking?', 'plugixa-car-parking'); ?></h3>

        <?php if (count($locations) > 1) : ?>
            <div class="pcp-slot" data-pcp-slot="location" hidden></div>
            <div class="pcp-field">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('Location', 'plugixa-car-parking'); ?></span>
                    <select class="pcp-input pcp-location" required>
                        <?php foreach ($locations as $loc) : ?>
                            <option value="<?php echo esc_attr((string) $loc['id']); ?>" data-slug="<?php echo esc_attr((string) ($loc['slug'] ?? '')); ?>" data-billing="<?php echo esc_attr((string) $loc['default_billing_type']); ?>" <?php selected($loc['id'], $default_location); ?>>
                                <?php echo esc_html($loc['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="pcp-field-error" hidden></p>
            </div>
        <?php else : ?>
            <input type="hidden" class="pcp-location" value="<?php echo esc_attr((string) $default_location); ?>" data-billing="<?php echo esc_attr((string) $locations[0]['default_billing_type']); ?>">
            <p class="pcp-location-name"><?php echo esc_html($locations[0]['name']); ?></p>
        <?php endif; ?>

        <div class="pcp-row pcp-dates">
            <div class="pcp-field">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('Entry', 'plugixa-car-parking'); ?></span>
                    <input type="datetime-local" class="pcp-input pcp-entry" required>
                </label>
                <p class="pcp-field-error" hidden></p>
            </div>
            <div class="pcp-field">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('Exit', 'plugixa-car-parking'); ?></span>
                    <input type="datetime-local" class="pcp-input pcp-exit" required>
                </label>
                <p class="pcp-field-error" hidden></p>
            </div>
        </div>
        <div class="pcp-stay">
            <div class="pcp-quick" role="group" aria-label="<?php esc_attr_e('Quick stay lengths', 'plugixa-car-parking'); ?>" hidden></div>
            <p class="pcp-duration" aria-live="polite" hidden></p>
        </div>

        <div class="pcp-actions pcp-actions-single">
            <button type="button" class="pcp-btn pcp-btn-primary pcp-btn-block pcp-check"><?php esc_html_e('Check availability', 'plugixa-car-parking'); ?></button>
        </div>
    </section>

    <!-- Step 2: choose space -->
    <section class="pcp-panel" data-panel="2">
        <h3 class="pcp-h"><?php esc_html_e('Choose a space', 'plugixa-car-parking'); ?></h3>
        <div class="pcp-space-types" role="radiogroup" aria-label="<?php esc_attr_e('Choose a space', 'plugixa-car-parking'); ?>"></div>
        <div class="pcp-space-picker" hidden></div>
        <div class="pcp-slot" data-pcp-slot="options" hidden></div>
        <div class="pcp-field pcp-qty-field">
            <?php
            /*
             * The minus and plus sit outside the label, over the ends of the field: a label
             * names the first control inside it, and that has to be the number field.
             */
            ?>
            <label class="pcp-label">
                <span class="pcp-label-text"><?php esc_html_e('Number of spaces', 'plugixa-car-parking'); ?></span>
                <span class="pcp-counter"><input type="number" class="pcp-input pcp-qty" value="1" min="1" step="1" inputmode="numeric" required></span>
            </label>
            <button type="button" class="pcp-counter-btn pcp-counter-less" data-step="-1" aria-label="<?php esc_attr_e('One space fewer', 'plugixa-car-parking'); ?>">&minus;</button>
            <button type="button" class="pcp-counter-btn pcp-counter-more" data-step="1" aria-label="<?php esc_attr_e('One space more', 'plugixa-car-parking'); ?>">+</button>
        </div>
        <div class="pcp-field pcp-coupon-field" hidden>
            <div class="pcp-coupon-row">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('Coupon code', 'plugixa-car-parking'); ?></span>
                    <input type="text" class="pcp-input pcp-coupon" autocomplete="off" autocapitalize="characters" spellcheck="false">
                </label>
                <button type="button" class="pcp-btn pcp-btn-secondary pcp-apply-coupon"><?php esc_html_e('Apply', 'plugixa-car-parking'); ?></button>
            </div>
            <p class="pcp-coupon-msg" role="status" hidden></p>
        </div>
        <div class="pcp-actions">
            <button type="button" class="pcp-btn pcp-btn-ghost pcp-back"><?php esc_html_e('Back', 'plugixa-car-parking'); ?></button>
            <button type="button" class="pcp-btn pcp-btn-primary pcp-to-details"><?php esc_html_e('Continue', 'plugixa-car-parking'); ?></button>
        </div>
    </section>

    <!-- Step 3: details -->
    <section class="pcp-panel" data-panel="3">
        <h3 class="pcp-h"><?php esc_html_e('Your details', 'plugixa-car-parking'); ?></h3>
        <div class="pcp-row">
            <div class="pcp-field">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('Full name', 'plugixa-car-parking'); ?><span class="pcp-req" aria-hidden="true"> *</span></span>
                    <input type="text" class="pcp-input pcp-name" autocomplete="name" required>
                </label>
                <p class="pcp-field-error" hidden></p>
            </div>
            <div class="pcp-field">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('Email', 'plugixa-car-parking'); ?><span class="pcp-req" aria-hidden="true"> *</span></span>
                    <input type="email" class="pcp-input pcp-email" autocomplete="email" inputmode="email" spellcheck="false" required>
                </label>
                <p class="pcp-field-error" hidden></p>
            </div>
        </div>
        <div class="pcp-row">
            <div class="pcp-field">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('Phone', 'plugixa-car-parking'); ?></span>
                    <input type="tel" class="pcp-input pcp-phone" autocomplete="tel" inputmode="tel">
                </label>
            </div>
            <div class="pcp-field">
                <label class="pcp-label">
                    <span class="pcp-label-text"><?php esc_html_e('License plate', 'plugixa-car-parking'); ?></span>
                    <input type="text" class="pcp-input pcp-plate" autocomplete="off" autocapitalize="characters" spellcheck="false">
                </label>
            </div>
        </div>
        <div class="pcp-custom-fields" hidden></div>
        <div class="pcp-slot" data-pcp-slot="details" hidden></div>
        <?php
        /*
         * A field no person sees or reaches, so a request that fills it was not typed by one.
         * The `hidden` attribute hides it without any stylesheet; nothing should un-hide it.
         */
        ?>
        <div class="pcp-hp" hidden aria-hidden="true">
            <label><?php esc_html_e('Leave this field empty', 'plugixa-car-parking'); ?> <input type="text" class="pcp-hp-field" name="pcp_hp" value="" tabindex="-1" autocomplete="off"></label>
        </div>
        <div class="pcp-actions">
            <button type="button" class="pcp-btn pcp-btn-ghost pcp-back"><?php esc_html_e('Back', 'plugixa-car-parking'); ?></button>
            <button type="button" class="pcp-btn pcp-btn-primary pcp-to-review"><?php esc_html_e('Review', 'plugixa-car-parking'); ?></button>
        </div>
    </section>

    <!-- Payment method: a screen only when there is more than one way to pay -->
    <section class="pcp-panel" data-panel="payment">
        <h3 class="pcp-h"><?php esc_html_e('How would you like to pay?', 'plugixa-car-parking'); ?></h3>

        <div class="pcp-pay-choice"></div>

        <div class="pcp-actions">
            <button type="button" class="pcp-btn pcp-btn-ghost pcp-back"><?php esc_html_e('Back', 'plugixa-car-parking'); ?></button>
            <button type="button" class="pcp-btn pcp-btn-primary pcp-to-next"><?php esc_html_e('Continue', 'plugixa-car-parking'); ?></button>
        </div>
    </section>

    <!-- Step 4: confirm -->
    <section class="pcp-panel" data-panel="4">
        <h3 class="pcp-h"><?php esc_html_e('Review and confirm', 'plugixa-car-parking'); ?></h3>
        <div class="pcp-review"></div>
        <div class="pcp-slot" data-pcp-slot="payment" hidden></div>
        <div class="pcp-actions">
            <button type="button" class="pcp-btn pcp-btn-ghost pcp-back"><?php esc_html_e('Back', 'plugixa-car-parking'); ?></button>
            <button type="button" class="pcp-btn pcp-btn-primary pcp-confirm"><?php esc_html_e('Confirm booking', 'plugixa-car-parking'); ?></button>
        </div>
    </section>

    <!-- Confirmation -->
    <section class="pcp-panel pcp-success-panel" data-panel="success">
        <div class="pcp-success"></div>
    </section>

    </div><!-- /.pcp-panels -->

    <?php
    /*
     * The one order summary. Drawn entirely by renderOrder() in booking-wizard.js
     * from the form's own state, so it stays hidden without the script instead of
     * showing a hollow card. Beside the steps when the form is wide enough, under
     * them otherwise. The live region is separate from the card so a change of
     * price is announced once, as one short sentence.
     */
    ?>
    <aside class="pcp-order" aria-label="<?php esc_attr_e('Your booking', 'plugixa-car-parking'); ?>" hidden>
        <?php
        /*
         * On a narrow form the summary folds into a bar showing the total, and this button
         * opens it. On a wide one the summary is always open and the button is not shown.
         */
        ?>
        <button type="button" class="pcp-order-toggle" aria-expanded="false">
            <span class="pcp-order-toggle-label"><?php esc_html_e('Your booking', 'plugixa-car-parking'); ?></span>
            <span class="pcp-order-toggle-total"></span>
            <svg class="pcp-order-toggle-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="pcp-order-body"></div>
        <p class="pcp-sr pcp-order-live" role="status" aria-live="polite"></p>
    </aside>

    </div><!-- /.pcp-body -->
</div><!-- /.pcp-wizard -->
</div><!-- /.pcp-wizard-shell -->
<?php
