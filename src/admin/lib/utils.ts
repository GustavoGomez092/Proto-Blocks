import { clsx, type ClassValue } from "clsx"
import { extendTailwindMerge } from "tailwind-merge"

/**
 * The type-scale tokens in styles/tokens.css (text-title, text-label…) are
 * font sizes. Without telling tailwind-merge, it reads them as text colours
 * and drops them when a colour class (text-muted-foreground) follows.
 */
const twMerge = extendTailwindMerge({
  extend: {
    theme: {
      text: [
        "title",
        "stat",
        "dialog",
        "section",
        "body",
        "label",
        "caption",
        "micro",
        "eyebrow",
      ],
    },
  },
})

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
