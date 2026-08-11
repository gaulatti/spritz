(function (wp) {
    const { Button, Spinner } = wp.components;
    const { useDispatch, useSelect } = wp.data;
    const { date, dateI18n, getDate } = wp.date;
    const { PluginDocumentSettingPanel } = wp.editor;
    const { createElement: el, useMemo } = wp.element;
    const { __, _n, sprintf } = wp.i18n;
    const { registerPlugin } = wp.plugins;

    const HALF_HOUR_MS = 30 * 60 * 1000;

    function upcomingBlocks() {
        const now = new Date();
        const siteMinute = Number(date('i', now));
        const remainder = 30 - (siteMinute % 30);
        const first = new Date(
            now.getTime() +
            remainder * 60 * 1000 -
            now.getSeconds() * 1000 -
            now.getMilliseconds()
        );

        return Array.from({ length: 12 }, function (_, index) {
            const start = new Date(first.getTime() + index * HALF_HOUR_MS);
            return {
                start,
                end: new Date(start.getTime() + HALF_HOUR_MS),
                label: dateI18n('g:i a', start),
            };
        });
    }

    function postDate(post) {
        if (post.date_gmt) {
            return new Date(post.date_gmt.replace(/Z$/, '') + 'Z');
        }

        return getDate(post.date);
    }

    function ScheduleBlocksPanel() {
        const blocks = useMemo(upcomingBlocks, []);
        const query = useMemo(function () {
            return {
                status: 'future',
                after: blocks[0].start.toISOString(),
                before: blocks[blocks.length - 1].end.toISOString(),
                order: 'asc',
                orderby: 'date',
                per_page: 100,
            };
        }, [blocks]);

        const editor = useSelect(function (select) {
            const editorStore = select('core/editor');
            return {
                date: editorStore.getEditedPostAttribute('date'),
                postType: editorStore.getCurrentPostType(),
                status: editorStore.getEditedPostAttribute('status'),
            };
        }, []);

        const schedule = useSelect(function (select) {
            const coreStore = select('core');
            const args = ['postType', 'post', query];
            return {
                posts: coreStore.getEntityRecords.apply(coreStore, args),
                loaded: coreStore.hasFinishedResolution('getEntityRecords', args),
            };
        }, [query]);

        const { editPost } = useDispatch('core/editor');

        if (editor.postType !== 'post') return null;

        const selectedDate = editor.date ? getDate(editor.date) : null;
        const selectedBlock = selectedDate
            ? blocks.find(function (block) {
                return selectedDate >= block.start && selectedDate < block.end;
            })
            : null;
        const published = editor.status === 'publish';

        function selectBlock(block) {
            const minute = Math.floor(Math.random() * 30);
            const publishAt = new Date(block.start.getTime() + minute * 60 * 1000);
            editPost({ date: date('Y-m-d\\TH:i:s', publishAt) });
        }

        return el(
            PluginDocumentSettingPanel,
            {
                name: 'schedule-blocks',
                title: __('Schedule block', 'spritz'),
                className: 'spritz-schedule-blocks',
            },
            published
                ? el(
                    'p',
                    null,
                    __('This post has already been published.', 'spritz')
                )
                : [
                    el(
                        'p',
                        { key: 'help', style: { marginTop: 0 } },
                        __('Choose a 30-minute block. The exact publish minute is selected automatically.', 'spritz')
                    ),
                    !schedule.loaded
                        ? el(
                            'div',
                            { key: 'loading', style: { padding: '12px 0' } },
                            el(Spinner)
                        )
                        : el(
                            'div',
                            {
                                key: 'blocks',
                                style: {
                                    display: 'grid',
                                    gridTemplateColumns: 'repeat(3, minmax(0, 1fr))',
                                    gap: '8px',
                                },
                            },
                            blocks.map(function (block) {
                                const count = (schedule.posts || []).filter(function (post) {
                                    const date = postDate(post);
                                    return date >= block.start && date < block.end;
                                }).length;
                                const selected = selectedBlock === block;

                                return el(
                                    Button,
                                    {
                                        key: block.start.toISOString(),
                                        variant: selected ? 'primary' : 'secondary',
                                        onClick: function () { selectBlock(block); },
                                        style: {
                                            height: 'auto',
                                            justifyContent: 'center',
                                            minHeight: '44px',
                                            padding: '6px',
                                            textAlign: 'center',
                                        },
                                    },
                                    el(
                                        'span',
                                        null,
                                        el('span', { style: { display: 'block' } }, block.label),
                                        el(
                                            'small',
                                            { style: { display: 'block', opacity: 0.75 } },
                                            sprintf(
                                                _n('%d scheduled', '%d scheduled', count, 'spritz'),
                                                count
                                            )
                                        )
                                    )
                                );
                            })
                        ),
                    selectedDate && (selectedBlock || editor.status === 'future')
                        ? el(
                            'p',
                            { key: 'selected', style: { marginBottom: 0 } },
                            sprintf(
                                __('Will publish %s.', 'spritz'),
                                dateI18n('M j, Y g:i a', selectedDate)
                            )
                        )
                        : null,
                ]
        );
    }

    registerPlugin('spritz-schedule-blocks', {
        render: ScheduleBlocksPanel,
        icon: 'calendar-alt',
    });
})(window.wp);
