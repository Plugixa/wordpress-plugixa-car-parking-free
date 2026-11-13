/*
 * Copyright (C) 2026 Plugixa (https://plugixa.com)
 * License: GNU General Public License v2 or later
 *
 * Buildless editor script for the booking-form block. Uses the wp.* globals
 * (no bundler step) and ServerSideRender, so the preview is the same markup
 * the page will show, styled by the same stylesheet.
 *
 * The forms and locations offered in the sidebar arrive from PHP in
 * `PlugixaCarParkingBlock`.
 */
(function (blocks, element, serverSideRender, blockEditor, components, i18n) {
  var el = element.createElement;
  var __ = i18n.__;
  var ServerSideRender = serverSideRender; // default export is the component
  var useBlockProps = blockEditor.useBlockProps;
  var InspectorControls = blockEditor.InspectorControls;
  var PanelBody = components.PanelBody;
  var SelectControl = components.SelectControl;
  var Disabled = components.Disabled;

  var DATA = window.PlugixaCarParkingBlock || {};

  /**
   * The options of a select: "none chosen" first, then what the site has, then
   * the stored value when it is no longer among them, so opening the sidebar
   * never silently changes what a published page says.
   */
  function options(list, current, emptyLabel) {
    var out = [{ value: '', label: emptyLabel }].concat(list || []);
    if (current && !out.some(function (o) { return o.value === current; })) {
      out.push({ value: current, label: current });
    }
    return out;
  }

  /**
   * The locations the chosen form books at. A form carries their ids, empty
   * for all of them; no form chosen means the site's default form, whose ids
   * arrive as `defaultLocations`. A location outside them would be ignored on
   * the page, so it is not offered.
   */
  function locationsFor(formSlug) {
    var form = (DATA.forms || []).find(function (f) { return f.value === formSlug; });
    var ids = form ? form.locations : DATA.defaultLocations;

    if (!ids || !ids.length) {
      return DATA.locations || [];
    }

    return (DATA.locations || []).filter(function (l) { return ids.indexOf(l.id) !== -1; });
  }

  blocks.registerBlockType('plugixacarparking/booking-form', {
    edit: function (props) {
      var attributes = props.attributes;

      return el(
        'div',
        useBlockProps(),
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: __('Booking form', 'plugixa-car-parking'), initialOpen: true },
            el(SelectControl, {
              label: __('Form', 'plugixa-car-parking'),
              help: __('A saved form sets the steps, its own fields, the colour and the design.', 'plugixa-car-parking'),
              value: attributes.form || '',
              options: options(DATA.forms, attributes.form, __('Standard form', 'plugixa-car-parking')),
              onChange: function (v) { props.setAttributes({ form: v }); },
              __nextHasNoMarginBottom: true,
              __next40pxDefaultSize: true,
            }),
            el(SelectControl, {
              label: __('Design', 'plugixa-car-parking'),
              help: __('How the form looks on this page. Split needs a wide block to show its side column.', 'plugixa-car-parking'),
              value: attributes.design || '',
              options: [
                { value: '', label: __('As the form is set', 'plugixa-car-parking') },
                { value: 'classic', label: __('Classic', 'plugixa-car-parking') },
                { value: 'split', label: __('Split', 'plugixa-car-parking') },
                { value: 'minimal', label: __('Minimal', 'plugixa-car-parking') },
              ],
              onChange: function (v) { props.setAttributes({ design: v }); },
              __nextHasNoMarginBottom: true,
              __next40pxDefaultSize: true,
            }),
            el(SelectControl, {
              label: __('Location', 'plugixa-car-parking'),
              help: __('The location the form starts on.', 'plugixa-car-parking'),
              value: attributes.location || '',
              options: options(locationsFor(attributes.form || ''), attributes.location, __('First available', 'plugixa-car-parking')),
              onChange: function (v) { props.setAttributes({ location: v }); },
              __nextHasNoMarginBottom: true,
              __next40pxDefaultSize: true,
            })
          )
        ),
        // A preview, not a working form: clicks select the block.
        el(
          Disabled,
          {},
          el(ServerSideRender, {
            block: 'plugixacarparking/booking-form',
            attributes: attributes,
          })
        )
      );
    },
    save: function () {
      return null; // dynamic block — rendered server-side
    },
  });
})(
  window.wp.blocks,
  window.wp.element,
  window.wp.serverSideRender,
  window.wp.blockEditor,
  window.wp.components,
  window.wp.i18n
);
