(function (wp) {
    const { RichTextToolbarButton } = wp.blockEditor;
    const { createElement: el } = wp.element;
    const { __ } = wp.i18n;
    const { registerFormatType, toggleFormat } = wp.richText;

    const FORMAT_NAME = 'spritz/highlight';

    registerFormatType(FORMAT_NAME, {
        title: __('Highlighter', 'spritz'),
        tagName: 'mark',
        className: 'spritz-highlight',
        edit: function HighlighterButton(props) {
            return el(RichTextToolbarButton, {
                icon: 'editor-textcolor',
                title: __('Highlighter', 'spritz'),
                isActive: props.isActive,
                onClick: function () {
                    props.onChange(toggleFormat(props.value, { type: FORMAT_NAME }));
                },
            });
        },
    });
})(window.wp);
