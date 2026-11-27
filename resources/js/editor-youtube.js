import { Plugin } from '@milkdown/kit/prose/state';
import { Decoration, DecorationSet } from '@milkdown/kit/prose/view';
import { $prose } from '@milkdown/kit/utils';
import { youtubeEmbedTitle, youtubeFrameProperties, youtubeVideoId } from './youtube-embed.mjs';

export const editorYoutube = $prose(() => new Plugin({
    props: {
        decorations(state) {
            const previews = [];
            state.doc.descendants((node, position) => {
                if (node.type.name !== 'paragraph' || node.childCount !== 1 || !node.firstChild.isText) return;

                const link = node.firstChild.marks.find((mark) => mark.type.name === 'link');
                if (link?.attrs.title !== youtubeEmbedTitle) return;

                const id = youtubeVideoId(link.attrs.href);
                if (!id) return;

                previews.push(Decoration.widget(position + node.nodeSize, (view) => {
                    const frame = view.dom.ownerDocument.createElement('iframe');
                    const { className, allowFullScreen, ...properties } = youtubeFrameProperties(id);
                    Object.assign(frame, properties, { className: className.join(' '), allowFullscreen: allowFullScreen });
                    return frame;
                }, { key: `youtube-${id}`, side: -1, ignoreSelection: true }));
            });

            return DecorationSet.create(state.doc, previews);
        },
    },
}));
