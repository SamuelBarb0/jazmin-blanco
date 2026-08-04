import { ImgHTMLAttributes } from 'react';

/**
 * Emblema de Aurum Clinic System. Es un PNG con fondo transparente y color
 * propio (dorado + azul), así que — a diferencia del SVG que había antes — NO
 * hereda el color del texto: las clases `fill-current`/`text-*` no le afectan.
 */
export default function AppLogoIcon(props: ImgHTMLAttributes<HTMLImageElement>) {
    return <img src="/logo-aurum-mark.png" alt="Aurum Clinic System" {...props} />;
}
