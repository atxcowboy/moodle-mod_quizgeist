import * as QRCode from 'qrcode';

export async function renderLocalQrCode(
  container: HTMLElement,
  value: string,
  accessibleLabel: string,
): Promise<void> {
  const canvas = document.createElement('canvas');
  canvas.className = 'quizgeist-host-qr__canvas';
  canvas.setAttribute('role', 'img');
  canvas.setAttribute('aria-label', accessibleLabel);
  canvas.textContent = accessibleLabel;

  await QRCode.toCanvas(canvas, value, {
    color: {
      dark: '#1B1B1FFF',
      light: '#FFFFFFFF',
    },
    errorCorrectionLevel: 'M',
    margin: 4,
    width: 188,
  });
  container.replaceChildren(canvas);
}
