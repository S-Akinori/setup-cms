import './bootstrap';
import { CrepeBuilder } from '@milkdown/crepe/builder';
import { blockEdit } from '@milkdown/crepe/feature/block-edit';
import { codeMirror } from '@milkdown/crepe/feature/code-mirror';
import { cursor } from '@milkdown/crepe/feature/cursor';
import { imageBlock } from '@milkdown/crepe/feature/image-block';
import { latex } from '@milkdown/crepe/feature/latex';
import { linkTooltip } from '@milkdown/crepe/feature/link-tooltip';
import { listItem } from '@milkdown/crepe/feature/list-item';
import { placeholder } from '@milkdown/crepe/feature/placeholder';
import { toolbar } from '@milkdown/crepe/feature/toolbar';
import { commandsCtx } from '@milkdown/kit/core';
import { callCommand, insert } from '@milkdown/kit/utils';
import {
    clearTextInCurrentBlockCommand,
    insertImageCommand,
    wrapInBulletListCommand,
    wrapInHeadingCommand,
    wrapInOrderedListCommand,
} from '@milkdown/kit/preset/commonmark';
import '@milkdown/crepe/theme/common/style.css';
import '@milkdown/crepe/theme/frame.css';

const markdownField = document.querySelector('[data-markdown]');
const editorRoot = document.querySelector('[data-rich-markdown-editor]');
const editorShell = document.querySelector('.markdown-editor-shell');
const editorLoading = document.querySelector('[data-editor-loading]');
const uploadStatus = document.querySelector('[data-upload-status]');
const altDialog = document.querySelector('[data-alt-dialog]');
const altInput = document.querySelector('[data-alt-input]');
const altConfirm = document.querySelector('[data-alt-confirm]');
const altCancel = document.querySelector('[data-alt-cancel]');
const imageCommand = document.querySelector('[data-image-command]');
const imageInput = document.querySelector('[data-image-input]');
const mediaDialog = document.querySelector('[data-media-dialog]');
const mediaDialogClose = document.querySelector('[data-media-dialog-close]');
const imageUploadTrigger = document.querySelector('[data-image-upload-trigger]');
const mediaLibrary = document.querySelector('[data-media-library]');
const mediaSearchInput = document.querySelector('[data-media-search]');
const mediaSearchSubmit = document.querySelector('[data-media-search-submit]');
const mediaLibraryStatus = document.querySelector('[data-media-library-status]');
const mediaLoadMore = document.querySelector('[data-media-load-more]');
const commandButtons = document.querySelectorAll('[data-markdown-command]');
const contentCommandButtons = document.querySelectorAll('[data-content-command]');
const standaloneUrlPattern = /^https?:\/\/[^\s<>]+$/i;
const contentTemplates = {
    button: '[ボタンの文字](https://example.com "cms-button:primary")',
    box: '> **ポイント**\n>\n> ここに内容を入力してください。',
};
const contentCommandIcons = {
    button: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="7" width="18" height="10" rx="2"></rect><path d="M8 12h8"></path><path d="m14 10 2 2-2 2"></path></svg>',
    box: '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M7 9h10"></path><path d="M7 13h7"></path></svg>',
};

const addContentSlashCommands = (builder) => {
    const group = builder.addGroup('cms-content', 'CMS');
    group
        .addItem('cms-button', {
            label: 'ボタン',
            icon: contentCommandIcons.button,
            onRun: (ctx) => {
                ctx.get(commandsCtx).call(clearTextInCurrentBlockCommand.key);
                insert(contentTemplates.button)(ctx);
            },
        })
        .addItem('cms-box', {
            label: '囲い',
            icon: contentCommandIcons.box,
            onRun: (ctx) => {
                ctx.get(commandsCtx).call(clearTextInCurrentBlockCommand.key);
                insert(contentTemplates.box)(ctx);
            },
        });
};

const normalizeMarkdown = (markdown) => {
    const lines = markdown.replace(/\r\n?/g, '\n').split('\n');
    let fenceCharacter = null;
    let fenceLength = 0;

    return lines.map((line) => {
        const fence = line.match(/^[ \t]{0,3}(`{3,}|~{3,})/);
        if (fence) {
            const character = fence[1][0];
            const length = fence[1].length;
            if (fenceCharacter === null) {
                fenceCharacter = character;
                fenceLength = length;
            } else if (character === fenceCharacter && length >= fenceLength && /^[ \t]{0,3}(`{3,}|~{3,})[ \t]*$/.test(line)) {
                fenceCharacter = null;
                fenceLength = 0;
            }

            return line;
        }

        if (fenceCharacter === null && /^[ \t]{0,3}<br\s*\/?>[ \t]*$/i.test(line)) {
            return '';
        }

        if (fenceCharacter === null) {
            const legacyBoxTitle = line.match(/^([ \t]*>[ \t]*)\[([^\]]+)\]\(#[ \t]+"cms-box:info"\)[ \t]*$/);
            if (legacyBoxTitle) return legacyBoxTitle[1] + '**' + legacyBoxTitle[2] + '**';
        }

        if (fenceCharacter === null && standaloneUrlPattern.test(line.trim())) {
            return `<${line.trim()}>`;
        }

        return line;
    }).join('\n');
};

if (markdownField && editorRoot && editorShell && uploadStatus && altDialog && altInput && altConfirm && altCancel) {
    const acceptedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const maxFileSize = 10 * 1024 * 1024;
    let isUploading = false;

    const setStatus = (message, state = '') => {
        uploadStatus.textContent = message;
        uploadStatus.className = `upload-status${state ? ` is-${state}` : ''}`;
    };

    const defaultAlt = (fileName) => fileName.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();

    const requestAltText = (file) => new Promise((resolve) => {
        let settled = false;

        const finish = (value) => {
            if (settled) return;
            settled = true;
            altConfirm.removeEventListener('click', confirm);
            altCancel.removeEventListener('click', cancel);
            altInput.removeEventListener('keydown', keydown);
            altDialog.removeEventListener('cancel', cancelDialog);
            if (altDialog.open) altDialog.close();
            resolve(value);
        };

        const confirm = () => {
            const value = altInput.value.trim();
            if (!value) {
                altInput.setCustomValidity('代替テキストを入力してください。');
                altInput.reportValidity();
                return;
            }
            altInput.setCustomValidity('');
            finish(value);
        };
        const cancel = () => finish(null);
        const cancelDialog = (event) => {
            event.preventDefault();
            finish(null);
        };
        const keydown = (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                confirm();
            }
        };

        altInput.value = defaultAlt(file.name);
        altInput.setCustomValidity('');
        altConfirm.addEventListener('click', confirm);
        altCancel.addEventListener('click', cancel);
        altInput.addEventListener('keydown', keydown);
        altDialog.addEventListener('cancel', cancelDialog);
        altDialog.showModal();
        altInput.select();
    });

    const uploadImage = async (file) => {
        if (!acceptedTypes.has(file.type) || file.size > maxFileSize) {
            throw new Error(`${file.name} は対応形式ではないか、10MBを超えています。`);
        }
        if (isUploading) {
            throw new Error('現在の画像アップロードが完了するまでお待ちください。');
        }

        const alt = await requestAltText(file);
        if (alt === null) throw new Error('画像の挿入をキャンセルしました。');

        isUploading = true;
        editorShell.classList.add('is-uploading');
        setStatus('画像をアップロードしています。', 'loading');

        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const formData = new FormData();
            formData.append('image', file);
            formData.append('alt_text', alt);

            const response = await fetch(editorShell.dataset.uploadUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                credentials: 'same-origin',
                body: formData,
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const validationMessage = Object.values(payload.errors ?? {}).flat()[0];
                throw new Error(validationMessage || payload.message || '画像をアップロードできませんでした。');
            }

            setStatus('画像を本文へ挿入しました。', 'success');
            return payload.data;
        } catch (error) {
            setStatus(error instanceof Error ? error.message : '画像をアップロードできませんでした。', 'error');
            throw error;
        } finally {
            isUploading = false;
            editorShell.classList.remove('is-uploading');
        }
    };

    const initialMarkdown = normalizeMarkdown(markdownField.value);
    markdownField.value = initialMarkdown;
    const crepe = new CrepeBuilder({
        root: editorRoot,
        defaultValue: initialMarkdown,
    })
        .addFeature(cursor)
        .addFeature(listItem)
        .addFeature(linkTooltip)
        .addFeature(imageBlock, {
            onUpload: async (file) => (await uploadImage(file)).url,
            inlineOnUpload: async (file) => (await uploadImage(file)).url,
            blockOnUpload: async (file) => (await uploadImage(file)).url,
            blockCaptionPlaceholderText: '画像の説明を入力',
            blockUploadButton: '画像を選択',
            blockConfirmButton: '挿入',
            blockUploadPlaceholderText: '画像URLを入力、または画像を選択',
            inlineUploadButton: '画像を選択',
            inlineConfirmButton: '挿入',
            inlineUploadPlaceholderText: '画像URLを入力、または画像を選択',
        })
        .addFeature(blockEdit, {
            buildMenu: addContentSlashCommands,
        })
        .addFeature(toolbar)
        .addFeature(placeholder, {
            text: '本文を入力してください。Markdown記法とLaTeXが使えます。',
            mode: 'block',
        })
        .addFeature(codeMirror)
        .addFeature(latex);

    crepe.on((listener) => {
        listener.markdownUpdated((ctx, markdown) => {
            markdownField.value = normalizeMarkdown(markdown);
        });
    });

    crepe.create().then(() => {
        editorLoading?.remove();
        editorShell.classList.add('is-ready');
        commandButtons.forEach((button) => { button.disabled = false; });
        contentCommandButtons.forEach((button) => { button.disabled = false; });
        if (imageCommand) imageCommand.disabled = false;

        const proseMirror = editorRoot.querySelector('.ProseMirror');
        const preventEditorLinkNavigation = (event) => {
            const link = event.target instanceof Element ? event.target.closest('a') : null;
            if (link && editorRoot.contains(link)) event.preventDefault();
        };
        editorRoot.addEventListener('click', preventEditorLinkNavigation, true);
        editorRoot.addEventListener('auxclick', preventEditorLinkNavigation, true);

        const commands = {
            h2: () => crepe.editor.action(callCommand(wrapInHeadingCommand.key, 2)),
            h3: () => crepe.editor.action(callCommand(wrapInHeadingCommand.key, 3)),
            ul: () => crepe.editor.action(callCommand(wrapInBulletListCommand.key)),
            ol: () => crepe.editor.action(callCommand(wrapInOrderedListCommand.key)),
        };

        const insertMedia = (media) => {
            if (!media?.url) {
                throw new Error('画像URLを取得できませんでした。');
            }

            crepe.editor.action(callCommand(insertImageCommand.key, {
                src: media.url,
                alt: media.alt || '',
                title: '',
            }));
            markdownField.value = normalizeMarkdown(crepe.getMarkdown());
            proseMirror?.focus();
            setStatus('画像を本文へ挿入しました。', 'success');
        };

        commandButtons.forEach((button) => {
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                commands[button.dataset.markdownCommand]?.();
                markdownField.value = normalizeMarkdown(crepe.getMarkdown());
            });
        });

        contentCommandButtons.forEach((button) => {
            button.addEventListener('mousedown', (event) => event.preventDefault());
            button.addEventListener('click', () => {
                const template = contentTemplates[button.dataset.contentCommand];
                if (!template) return;

                crepe.editor.action(insert(template));
                markdownField.value = normalizeMarkdown(crepe.getMarkdown());
                proseMirror?.focus();
            });
        });

        if (imageCommand && imageInput && mediaDialog && mediaLibrary) {
            let mediaLibraryLoaded = false;
            let mediaLibraryPage = 1;
            let mediaLibraryLastPage = 1;
            let mediaLibraryRequest = null;

            const createMediaOption = (media) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.className = 'media-library-item';
                option.dataset.mediaSelect = '';
                option.dataset.mediaUrl = media.url;
                option.dataset.mediaAlt = media.alt;
                option.title = `${media.alt}を挿入`;

                const thumbnail = document.createElement('img');
                thumbnail.src = media.url;
                thumbnail.alt = '';
                thumbnail.loading = 'lazy';
                const name = document.createElement('span');
                name.textContent = media.alt;
                const dimensions = document.createElement('small');
                dimensions.textContent = `${media.width} × ${media.height} px`;
                option.append(thumbnail, name, dimensions);

                return option;
            };

            const showLibraryMessage = (title, detail = '') => {
                const empty = document.createElement('div');
                empty.className = 'media-library-empty';
                const heading = document.createElement('strong');
                heading.textContent = title;
                empty.append(heading);
                if (detail) {
                    const text = document.createElement('span');
                    text.textContent = detail;
                    empty.append(text);
                }
                mediaLibrary.replaceChildren(empty);
            };

            const loadMediaLibrary = async (page = 1, append = false) => {
                mediaLibraryRequest?.abort();
                const controller = new AbortController();
                mediaLibraryRequest = controller;
                mediaLoadMore.disabled = true;
                if (!append) showLibraryMessage('画像を読み込んでいます。');
                if (mediaLibraryStatus) mediaLibraryStatus.textContent = '読み込み中';

                try {
                    const url = new URL(mediaDialog.dataset.libraryUrl, window.location.href);
                    const query = mediaSearchInput?.value.trim() ?? '';
                    url.searchParams.set('page', String(page));
                    if (query) url.searchParams.set('q', query);
                    const response = await fetch(url, {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });
                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok) throw new Error(payload.message || '画像を読み込めませんでした。');

                    if (!append) mediaLibrary.replaceChildren();
                    const fragment = document.createDocumentFragment();
                    payload.data.forEach((media) => fragment.append(createMediaOption(media)));
                    mediaLibrary.append(fragment);

                    mediaLibraryPage = payload.meta.currentPage;
                    mediaLibraryLastPage = payload.meta.lastPage;
                    mediaLibraryLoaded = true;
                    if (payload.data.length === 0 && !append) {
                        showLibraryMessage('該当する画像はありません。', '検索条件を変えてお試しください。');
                    }
                    if (mediaLibraryStatus) mediaLibraryStatus.textContent = `${payload.meta.total}件の画像`;
                    mediaLoadMore.hidden = mediaLibraryPage >= mediaLibraryLastPage;
                } catch (error) {
                    if (error.name === 'AbortError') return;
                    if (!append) showLibraryMessage('画像を読み込めませんでした。', '時間をおいて再度お試しください。');
                    if (mediaLibraryStatus) mediaLibraryStatus.textContent = error instanceof Error ? error.message : '読み込みに失敗しました。';
                } finally {
                    if (mediaLibraryRequest === controller) {
                        mediaLibraryRequest = null;
                        mediaLoadMore.disabled = false;
                    }
                }
            };

            imageCommand.addEventListener('click', () => {
                mediaDialog.showModal();
                if (!mediaLibraryLoaded) loadMediaLibrary();
            });
            mediaDialogClose?.addEventListener('click', () => mediaDialog.close());
            mediaDialog.addEventListener('click', (event) => {
                if (event.target === mediaDialog) mediaDialog.close();
            });
            imageUploadTrigger?.addEventListener('click', () => {
                mediaDialog.close();
                imageInput.click();
            });

            mediaLibrary.addEventListener('click', (event) => {
                const button = event.target.closest('[data-media-select]');
                if (!button || !mediaLibrary.contains(button)) return;

                try {
                    insertMedia({
                        url: button.dataset.mediaUrl,
                        alt: button.dataset.mediaAlt,
                    });
                    mediaDialog.close();
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : '画像を挿入できませんでした。', 'error');
                }
            });

            mediaSearchSubmit?.addEventListener('click', () => loadMediaLibrary());
            mediaSearchInput?.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                loadMediaLibrary();
            });
            mediaLoadMore?.addEventListener('click', () => {
                if (mediaLibraryPage < mediaLibraryLastPage) loadMediaLibrary(mediaLibraryPage + 1, true);
            });

            imageInput.addEventListener('change', async () => {
                const files = Array.from(imageInput.files ?? []);
                try {
                    for (const file of files) {
                        const media = await uploadImage(file);
                        insertMedia(media);
                        mediaLibraryLoaded = false;
                    }
                } catch (error) {
                    if (error instanceof Error && error.message === '画像の挿入をキャンセルしました。') {
                        setStatus(error.message);
                    }
                } finally {
                    imageInput.value = '';
                }
            });
        }

        const editorForm = markdownField.closest('form');
        editorForm?.addEventListener('submit', (event) => {
            markdownField.value = normalizeMarkdown(crepe.getMarkdown()).trim();
            if (!markdownField.value) {
                event.preventDefault();
                setStatus('本文を入力してください。', 'error');
                proseMirror?.focus();
            }
        });
        editorForm?.dispatchEvent(new CustomEvent('cms:editor-ready'));
    }).catch((error) => {
        console.error('Markdown editor initialization failed.', error);
        editorRoot.hidden = true;
        markdownField.hidden = false;
        markdownField.classList.add('markdown-editor', 'editor-fallback');
        if (editorLoading) editorLoading.textContent = 'リッチエディタを読み込めなかったため、Markdown入力へ切り替えました。';
        setStatus('エディタの初期化に失敗しました。ページを再読み込みしてください。', 'error');
    });
}

const title = document.querySelector('[data-title]');
const slug = document.querySelector('[data-slug]');
if (title && slug && !slug.value) {
    title.addEventListener('input', () => {
        slug.value = title.value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    });
}

const featuredPicker = document.querySelector('[data-featured-picker]');
if (featuredPicker) {
    const featuredId = featuredPicker.querySelector('[data-featured-id]');
    const previewImage = featuredPicker.querySelector('[data-featured-preview-image]');
    const emptyPreview = featuredPicker.querySelector('[data-featured-empty]');
    const caption = featuredPicker.querySelector('[data-featured-caption]');
    const openButton = featuredPicker.querySelector('[data-featured-open]');
    const removeButton = featuredPicker.querySelector('[data-featured-remove]');
    const dialog = featuredPicker.querySelector('[data-featured-dialog]');
    const closeButton = featuredPicker.querySelector('[data-featured-close]');
    const library = featuredPicker.querySelector('[data-featured-library]');
    const fileInput = featuredPicker.querySelector('[data-featured-file]');
    const altInput = featuredPicker.querySelector('[data-featured-alt]');
    const uploadButton = featuredPicker.querySelector('[data-featured-upload]');
    const status = featuredPicker.querySelector('[data-featured-status]');
    const acceptedTypes = new Set(['image/jpeg', 'image/png', 'image/webp']);
    const maxFileSize = 10 * 1024 * 1024;

    const setFeaturedStatus = (message, state = '') => {
        status.textContent = message;
        status.className = `upload-status featured-upload-status${state ? ` is-${state}` : ''}`;
    };

    const selectFeatured = ({ id, url, alt }) => {
        featuredId.value = id;
        previewImage.src = url;
        previewImage.alt = alt;
        previewImage.hidden = false;
        emptyPreview.hidden = true;
        caption.textContent = alt;
        removeButton.hidden = false;
        library.querySelectorAll('[data-featured-select]').forEach((button) => {
            button.setAttribute('aria-pressed', button.dataset.mediaId === String(id) ? 'true' : 'false');
        });
        dialog.close();
    };

    library.addEventListener('click', (event) => {
        const button = event.target.closest('[data-featured-select]');
        if (!button || !library.contains(button)) return;

        selectFeatured({
            id: button.dataset.mediaId,
            url: button.dataset.mediaUrl,
            alt: button.dataset.mediaAlt,
        });
    });

    openButton.addEventListener('click', () => dialog.showModal());
    closeButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
    removeButton.addEventListener('click', () => {
        featuredId.value = '';
        previewImage.src = '';
        previewImage.alt = '';
        previewImage.hidden = true;
        emptyPreview.hidden = false;
        caption.textContent = 'ギャラリーから選択するか、新しい画像をアップロードしてください。';
        removeButton.hidden = true;
        library.querySelectorAll('[data-featured-select]').forEach((button) => button.setAttribute('aria-pressed', 'false'));
    });

    fileInput.addEventListener('change', () => {
        const file = fileInput.files?.[0];
        if (file && !altInput.value.trim()) {
            altInput.value = file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
        }
        setFeaturedStatus('');
    });

    uploadButton.addEventListener('click', async () => {
        const file = fileInput.files?.[0];
        const alt = altInput.value.trim();
        if (!file) {
            setFeaturedStatus('アップロードする画像を選択してください。', 'error');
            fileInput.focus();
            return;
        }
        if (!acceptedTypes.has(file.type) || file.size > maxFileSize) {
            setFeaturedStatus('JPEG、PNG、WebP形式の10MB以下の画像を選択してください。', 'error');
            fileInput.focus();
            return;
        }
        if (!alt) {
            setFeaturedStatus('代替テキストを入力してください。', 'error');
            altInput.focus();
            return;
        }

        uploadButton.disabled = true;
        setFeaturedStatus('画像をアップロードしています。', 'loading');
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
            const formData = new FormData();
            formData.append('image', file);
            formData.append('alt_text', alt);
            const response = await fetch(featuredPicker.dataset.uploadUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    ...(csrfToken ? { 'X-CSRF-TOKEN': csrfToken } : {}),
                },
                credentials: 'same-origin',
                body: formData,
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) {
                const validationMessage = Object.values(payload.errors ?? {}).flat()[0];
                throw new Error(validationMessage || payload.message || '画像をアップロードできませんでした。');
            }

            const media = payload.data;
            library.querySelector('[data-featured-library-empty]')?.remove();
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'media-library-item featured-library-item';
            option.dataset.featuredSelect = '';
            option.dataset.mediaId = media.id;
            option.dataset.mediaUrl = media.url;
            option.dataset.mediaAlt = media.alt;
            option.setAttribute('aria-pressed', 'false');

            const thumbnail = document.createElement('img');
            thumbnail.src = media.url;
            thumbnail.alt = '';
            const name = document.createElement('span');
            name.textContent = media.alt;
            const dimensions = document.createElement('small');
            dimensions.textContent = `${media.width} × ${media.height} px`;
            option.append(thumbnail, name, dimensions);
            library.prepend(option);

            fileInput.value = '';
            altInput.value = '';
            setFeaturedStatus('アップロードしました。', 'success');
            selectFeatured(media);
        } catch (error) {
            setFeaturedStatus(error instanceof Error ? error.message : '画像をアップロードできませんでした。', 'error');
        } finally {
            uploadButton.disabled = false;
        }
    });
}

const wordpressImportForm = document.querySelector('[data-wordpress-import-form]');
const wordpressImportSubmit = document.querySelector('[data-wordpress-import-submit]');
const wordpressImportStatus = document.querySelector('[data-wordpress-import-status]');
if (wordpressImportForm && wordpressImportSubmit && wordpressImportStatus) {
    wordpressImportForm.addEventListener('submit', () => {
        wordpressImportSubmit.disabled = true;
        wordpressImportSubmit.textContent = '処理中…';
        wordpressImportStatus.hidden = false;
    });
}

const unsavedChangesForm = document.querySelector('[data-unsaved-changes-form]');
if (unsavedChangesForm) {
    const leaveMessage = '変更が保存されていません。このページを移動しますか？';
    let allowNavigation = false;

    const formSnapshot = () => Array.from(unsavedChangesForm.elements).map((field) => {
        if (!field.name || field.disabled || ['submit', 'button', 'reset'].includes(field.type)) return null;

        if (field.type === 'checkbox' || field.type === 'radio') {
            return [field.name, field.value, field.checked];
        }

        if (field.type === 'file') {
            return [field.name, Array.from(field.files ?? []).map((file) => [file.name, file.size, file.lastModified])];
        }

        if (field instanceof HTMLSelectElement && field.multiple) {
            return [field.name, Array.from(field.selectedOptions).map((option) => option.value)];
        }

        return [field.name, field.value];
    }).filter(Boolean);

    let initialSnapshot = JSON.stringify(formSnapshot());
    const hasUnsavedChanges = () => !allowNavigation && JSON.stringify(formSnapshot()) !== initialSnapshot;

    unsavedChangesForm.addEventListener('cms:editor-ready', () => {
        initialSnapshot = JSON.stringify(formSnapshot());
    });

    unsavedChangesForm.addEventListener('formdata', () => {
        allowNavigation = true;
    });

    window.addEventListener('beforeunload', (event) => {
        if (!hasUnsavedChanges()) return;
        event.preventDefault();
        event.returnValue = '';
    });

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('a[href]');
        if (!link || link.target === '_blank' || link.hasAttribute('download')) return;

        const destination = new URL(link.href, window.location.href);
        if (!['http:', 'https:'].includes(destination.protocol)) return;

        const current = new URL(window.location.href);
        if (destination.origin === current.origin && destination.pathname === current.pathname && destination.search === current.search) return;
        if (!hasUnsavedChanges()) return;

        if (!window.confirm(leaveMessage)) {
            event.preventDefault();
            return;
        }

        allowNavigation = true;
        window.setTimeout(() => { allowNavigation = false; }, 0);
    });
}
