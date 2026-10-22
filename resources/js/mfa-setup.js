import QRCode from 'qrcode';

const frame = document.querySelector('[data-mfa-qr-frame]');
const canvas = document.querySelector('[data-mfa-qr-canvas]');
const placeholder = document.querySelector('[data-mfa-qr-placeholder]');
const status = document.querySelector('[data-mfa-qr-status]');
const uri = document.querySelector('#mfa-otpauth-uri')?.value;
const t = (text) => window.wikiTranslate?.(text) ?? text;

if (frame && canvas && placeholder && status) {
    if (!uri) {
        frame.dataset.state = 'error';
        status.textContent = t('Could not create the QR code. Use the setup key below instead.');
    } else {
        QRCode.toCanvas(canvas, uri, {
            errorCorrectionLevel: 'M',
            margin: 3,
            width: 232,
            color: {dark: '#172B4D', light: '#FFFFFF'}
        }).then(() => {
            canvas.hidden = false;
            placeholder.hidden = true;
            frame.dataset.state = 'ready';
            status.textContent = t('QR code is ready to scan.');
        }).catch(() => {
            frame.dataset.state = 'error';
            status.textContent = t('Could not create the QR code. Use the setup key below instead.');
        });
    }
}
