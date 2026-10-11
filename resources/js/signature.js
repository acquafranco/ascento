// Firma de remitos: la librería va empaquetada (antes se cargaba de un CDN y
// sin conexión a ese CDN no se podía firmar).
import SignaturePad from 'signature_pad';

window.SignaturePad = SignaturePad;
