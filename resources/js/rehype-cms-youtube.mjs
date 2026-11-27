import { youtubeEmbedTitle, youtubeFrameProperties, youtubeVideoId } from './youtube-embed.mjs';

export default function rehypeCmsYoutube() {
    function transform(node) {
        if (node.type === 'element' && node.tagName === 'p' && node.children.length === 1) {
            const [link] = node.children;
            if (link.type === 'element' && link.tagName === 'a' && link.properties?.title === youtubeEmbedTitle) {
                const id = youtubeVideoId(link.properties.href);
                if (id) {
                    node.tagName = 'iframe';
                    node.properties = youtubeFrameProperties(id);
                    node.children = [];
                    return;
                }
            }
        }

        node.children?.forEach(transform);
    }

    return transform;
}
