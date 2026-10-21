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
import { callCommand } from '@milkdown/kit/utils';
import {
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
const mediaSelectButtons = document.querySelectorAll('[data-media-select]');
const commandButtons = document.querySelectorAll('[data-markdown-command]');

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

    const crepe = new CrepeBuilder({
        root: editorRoot,
        defaultValue: markdownField.value,
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
        .addFeature(blockEdit)
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
        if (imageCommand) imageCommand.disabled = false;

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
            editorRoot.querySelector('.ProseMirror')?.focus();
            setStatus('画像を本文へ挿入しました。', 'success');
        };

        commandButtons.forEach((button) => {
            button.addEventListener('click', () => {
                commands[button.dataset.markdownCommand]?.();
                markdownField.value = normalizeMarkdown(crepe.getMarkdown());
            });
        });

        if (imageCommand && imageInput && mediaDialog) {
            imageCommand.addEventListener('click', () => mediaDialog.showModal());
            mediaDialogClose?.addEventListener('click', () => mediaDialog.close());
            mediaDialog.addEventListener('click', (event) => {
                if (event.target === mediaDialog) mediaDialog.close();
            });
            imageUploadTrigger?.addEventListener('click', () => {
                mediaDialog.close();
                imageInput.click();
            });

            mediaSelectButtons.forEach((button) => {
                button.addEventListener('click', () => {
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
            });

            imageInput.addEventListener('change', async () => {
                const files = Array.from(imageInput.files ?? []);
                try {
                    for (const file of files) {
                        const media = await uploadImage(file);
                        insertMedia(media);
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

        markdownField.closest('form')?.addEventListener('submit', (event) => {
            markdownField.value = normalizeMarkdown(crepe.getMarkdown()).trim();
            if (!markdownField.value) {
                event.preventDefault();
                setStatus('本文を入力してください。', 'error');
                editorRoot.querySelector('.ProseMirror')?.focus();
            }
        });
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
