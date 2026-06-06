(function (blocks, blockEditor, components, element, i18n, serverSideRender) {
    'use strict';

    var el = element.createElement;
    var __ = i18n.__;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody = components.PanelBody;
    var RangeControl = components.RangeControl;
    var TextControl = components.TextControl;
    var SelectControl = components.SelectControl;
    var ToggleControl = components.ToggleControl;
    var ServerSideRender = serverSideRender;

    function preview(name, props) {
        return el('div', { className: 'lunara-dispatch-block-preview' },
            el(ServerSideRender, {
                block: name,
                attributes: props.attributes
            })
        );
    }

    function feedControls(props, includeLayout) {
        return el(InspectorControls, {},
            el(PanelBody, { title: __('Journal Query', 'lunara-dispatch') },
                el(RangeControl, {
                    label: __('Number of entries', 'lunara-dispatch'),
                    value: props.attributes.count,
                    min: 1,
                    max: 24,
                    onChange: function (value) {
                        props.setAttributes({ count: value || 1 });
                    }
                }),
                includeLayout ? el(SelectControl, {
                    label: __('Layout', 'lunara-dispatch'),
                    value: props.attributes.layout,
                    options: [
                        { label: 'Grid', value: 'grid' },
                        { label: 'List', value: 'list' },
                        { label: 'Rail', value: 'rail' }
                    ],
                    onChange: function (value) {
                        props.setAttributes({ layout: value });
                    }
                }) : null,
                el(TextControl, {
                    label: __('Journal type slug', 'lunara-dispatch'),
                    value: props.attributes.journalType,
                    help: __('Leave blank for all Journal entries.', 'lunara-dispatch'),
                    onChange: function (value) {
                        props.setAttributes({ journalType: value });
                    }
                })
            ),
            el(PanelBody, { title: __('Card Details', 'lunara-dispatch'), initialOpen: false },
                el(ToggleControl, {
                    label: __('Show image', 'lunara-dispatch'),
                    checked: props.attributes.showImage,
                    onChange: function (value) {
                        props.setAttributes({ showImage: value });
                    }
                }),
                el(ToggleControl, {
                    label: __('Show excerpt', 'lunara-dispatch'),
                    checked: props.attributes.showExcerpt,
                    onChange: function (value) {
                        props.setAttributes({ showExcerpt: value });
                    }
                }),
                el(ToggleControl, {
                    label: __('Show date', 'lunara-dispatch'),
                    checked: props.attributes.showDate,
                    onChange: function (value) {
                        props.setAttributes({ showDate: value });
                    }
                }),
                el(ToggleControl, {
                    label: __('Show Journal type', 'lunara-dispatch'),
                    checked: props.attributes.showType,
                    onChange: function (value) {
                        props.setAttributes({ showType: value });
                    }
                }),
                el(ToggleControl, {
                    label: __('Exclude archive lane', 'lunara-dispatch'),
                    checked: props.attributes.excludeArchive,
                    onChange: function (value) {
                        props.setAttributes({ excludeArchive: value });
                    }
                })
            )
        );
    }

    var feedAttributes = {
        count: { type: 'number', default: 6 },
        layout: { type: 'string', default: 'grid' },
        journalType: { type: 'string', default: '' },
        showImage: { type: 'boolean', default: true },
        showExcerpt: { type: 'boolean', default: true },
        showDate: { type: 'boolean', default: true },
        showType: { type: 'boolean', default: true },
        excludeArchive: { type: 'boolean', default: true }
    };

    blocks.registerBlockType('lunara-dispatch/journal-feed', {
        title: __('Lunara Journal Feed', 'lunara-dispatch'),
        icon: 'format-aside',
        category: 'lunara',
        attributes: feedAttributes,
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            return [feedControls(props, true), preview('lunara-dispatch/journal-feed', props)];
        },
        save: function () {
            return null;
        }
    });

    blocks.registerBlockType('lunara-dispatch/journal-spotlight', {
        title: __('Lunara Journal Spotlight', 'lunara-dispatch'),
        icon: 'welcome-view-site',
        category: 'lunara',
        attributes: Object.assign({}, feedAttributes, {
            count: { type: 'number', default: 4 },
            layout: { type: 'string', default: 'spotlight' }
        }),
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            return [feedControls(props, false), preview('lunara-dispatch/journal-spotlight', props)];
        },
        save: function () {
            return null;
        }
    });

    blocks.registerBlockType('lunara-dispatch/journal-lanes', {
        title: __('Lunara Journal Lanes', 'lunara-dispatch'),
        icon: 'category',
        category: 'lunara',
        attributes: {
            showCounts: { type: 'boolean', default: true },
            hideEmpty: { type: 'boolean', default: false }
        },
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            return [
                el(InspectorControls, {},
                    el(PanelBody, { title: __('Lanes', 'lunara-dispatch') },
                        el(ToggleControl, {
                            label: __('Show counts', 'lunara-dispatch'),
                            checked: props.attributes.showCounts,
                            onChange: function (value) {
                                props.setAttributes({ showCounts: value });
                            }
                        }),
                        el(ToggleControl, {
                            label: __('Hide empty lanes', 'lunara-dispatch'),
                            checked: props.attributes.hideEmpty,
                            onChange: function (value) {
                                props.setAttributes({ hideEmpty: value });
                            }
                        })
                    )
                ),
                preview('lunara-dispatch/journal-lanes', props)
            ];
        },
        save: function () {
            return null;
        }
    });
})(window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n, window.wp.serverSideRender);
