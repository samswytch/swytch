import {
  ALLOWED_IMAGE_TYPES,
  IMAGE_MAX_EDGE,
  MAX_ATTACHMENT_BASE64,
  PDF_TYPE,
  describeSize,
} from './limits';

/**
 * Turns a file she pasted, dropped or photographed into the base64 the API
 * wants. Runs in the browser.
 *
 * Nothing is uploaded anywhere. The bytes go into the message, the message goes
 * to the Anthropic API through our own route, and when she starts a new question
 * they are gone (BRIEF.md §6).
 *
 * Photographs off a phone are 3-6 MB and several times larger than the API can
 * use, so images are resized here. That is the difference between an attachment
 * working on the factory floor and timing out.
 */

export interface Attachment {
  id: string;
  kind: 'image' | 'document';
  mediaType: string;
  data: string;
  name: string;
  preview?: string;
}

export class AttachmentError extends Error {}

function blobToBase64(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onerror = () => reject(new AttachmentError('That file could not be read. Try attaching it again.'));
    reader.onload = () => {
      const result = String(reader.result);
      const comma = result.indexOf(',');
      if (comma < 0) reject(new AttachmentError('That file could not be read. Try attaching it again.'));
      else resolve(result.slice(comma + 1));
    };
    reader.readAsDataURL(blob);
  });
}

async function resizeImage(file: File): Promise<{ blob: Blob; mediaType: string }> {
  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch {
    throw new AttachmentError(
      `That image is in a format this browser cannot open${file.type ? ` (${file.type})` : ''}. ` +
        `Save it as a JPEG or PNG and attach it again.`,
    );
  }

  const longestEdge = Math.max(bitmap.width, bitmap.height);
  const scale = longestEdge > IMAGE_MAX_EDGE ? IMAGE_MAX_EDGE / longestEdge : 1;

  // Already small enough, and a format the API takes — send the original bytes
  // rather than re-encoding and losing detail on a proof.
  if (
    scale === 1 &&
    (ALLOWED_IMAGE_TYPES as readonly string[]).includes(file.type) &&
    file.size * 1.37 < MAX_ATTACHMENT_BASE64
  ) {
    bitmap.close();
    return { blob: file, mediaType: file.type };
  }

  const canvas = document.createElement('canvas');
  canvas.width = Math.max(1, Math.round(bitmap.width * scale));
  canvas.height = Math.max(1, Math.round(bitmap.height * scale));

  const context = canvas.getContext('2d');
  if (!context) throw new AttachmentError('That image could not be prepared. Try attaching it again.');
  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
  bitmap.close();

  const blob = await new Promise<Blob | null>((resolve) =>
    canvas.toBlob(resolve, 'image/jpeg', 0.9),
  );
  if (!blob) throw new AttachmentError('That image could not be prepared. Try attaching it again.');
  return { blob, mediaType: 'image/jpeg' };
}

export async function prepareFile(file: File): Promise<Attachment> {
  const id = crypto.randomUUID();
  const name = file.name || 'attachment';

  if (file.type === PDF_TYPE) {
    const data = await blobToBase64(file);
    if (data.length > MAX_ATTACHMENT_BASE64) {
      throw new AttachmentError(
        `${name} is ${describeSize(data.length)}, over the ${describeSize(MAX_ATTACHMENT_BASE64)} limit for ` +
          `one attachment. Export it at a lower resolution, or send just the page you want checked.`,
      );
    }
    return { id, kind: 'document', mediaType: PDF_TYPE, data, name };
  }

  if (!file.type.startsWith('image/')) {
    throw new AttachmentError(`${name} is not an image or a PDF. Those are the two things that can be attached.`);
  }

  const { blob, mediaType } = await resizeImage(file);
  const data = await blobToBase64(blob);
  if (data.length > MAX_ATTACHMENT_BASE64) {
    throw new AttachmentError(
      `${name} is still ${describeSize(data.length)} after resizing, over the ` +
        `${describeSize(MAX_ATTACHMENT_BASE64)} limit. Crop it to the part you want checked.`,
    );
  }

  return { id, kind: 'image', mediaType, data, name, preview: `data:${mediaType};base64,${data}` };
}
