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
