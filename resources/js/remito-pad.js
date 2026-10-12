// Recuadro de firma de remitos: la librería va empaquetada (antes se cargaba
// de un CDN y sin conexión a ese CDN no se podía firmar). Nombre neutro
// ("remito-pad"): evita que un bloqueador de contenido lo filtre por contener
// "signature" en el nombre (precaución; no se confirmó con una lista real).
// Si igual no carga, el formulario lo avisa en vez de dejar un recuadro mudo.
import SignaturePad from 'signature_pad';

window.SignaturePad = SignaturePad;
