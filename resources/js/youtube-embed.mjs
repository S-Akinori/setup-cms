export const youtubeEmbedTitle = 'cms-youtube';

export function youtubeVideoId(value) {
    if (typeof value !== 'string' || !/^https?:\/\//i.test(value.trim())) return null;

    let url;
    try {
        url = new URL(value.trim());
    } catch {
        return null;
    }

    if (url.username || url.password || url.port) return null;

    const host = url.hostname.replace(/^www\./, '');
    let id = null;
    if (host === 'youtu.be') {
        id = url.pathname.match(/^\/([^/]+)\/?$/)?.[1];
    } else if (['youtube.com', 'm.youtube.com'].includes(host)) {
        id = url.pathname === '/watch'
            ? url.searchParams.get('v')
            : url.pathname.match(/^\/(?:shorts|live|embed)\/([^/]+)\/?$/)?.[1];
    } else if (host === 'youtube-nocookie.com') {
        id = url.pathname.match(/^\/embed\/([^/]+)\/?$/)?.[1];
    }

    return typeof id === 'string' && /^[\w-]{11}$/.test(id) ? id : null;
}

export function youtubeFrameProperties(id) {
    return {
        src: `https://www.youtube-nocookie.com/embed/${id}`,
        title: 'YouTube動画',
        className: ['cms-youtube'],
        width: 560,
        height: 315,
        loading: 'lazy',
        referrerPolicy: 'strict-origin-when-cross-origin',
        allow: 'encrypted-media; picture-in-picture',
        allowFullScreen: true,
    };
}
