import { defineComponent, h } from 'vue';
import AppIcon from './AppIcon.vue';

// Named components keep dynamic component props and existing UI call sites typed.
// Artwork is stored once in a same-origin SVG sprite, never fetched from a CDN.
function icon(name: string) {
  return defineComponent({
    name: `Icon${name}`,
    props: { size: { type: [Number, String], default: 24 } },
    setup: (props) => () => h(AppIcon, { name, size: props.size }),
  });
}

export { AppIcon };
export const Building2 = icon('building');
export const Check = icon('check');
export const ChevronDown = icon('chevron-down');
export const ChevronLeft = icon('chevron-left');
export const ChevronRight = icon('chevron-right');
export const CircleAlert = icon('alert-circle');
export const CircleCheck = icon('check-circle');
export const Eye = icon('eye');
export const EyeOff = icon('eye-off');
export const Inbox = icon('inbox');
export const Info = icon('info');
export const Layers = icon('layers');
export const LayoutGrid = icon('grid');
export const LoaderCircle = icon('spinner');
export const LogIn = icon('login');
export const LogOut = icon('logout');
export const MailCheck = icon('mail');
export const Moon = icon('moon');
export const Pencil = icon('pencil');
export const Plus = icon('plus');
export const RotateCw = icon('restart');
export const ShieldCheck = icon('shield-check');
export const Settings = icon('settings');
export const Sun = icon('sun');
export const Tag = icon('tag');
export const Trash2 = icon('trash');
export const TriangleAlert = icon('alert-triangle');
export const X = icon('close');
