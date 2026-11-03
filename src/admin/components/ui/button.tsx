import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/admin/lib/utils"
import { Slot } from "radix-ui"

const buttonVariants = cva(
  "inline-flex shrink-0 cursor-pointer items-center justify-center gap-2 rounded-md text-body leading-none font-medium whitespace-nowrap no-underline transition-colors outline-none hover:no-underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground hover:bg-[#0b3a73] hover:text-primary-foreground",
        destructive:
          "bg-destructive text-white hover:bg-destructive/90 hover:text-white dark:bg-destructive/60",
        outline:
          "border border-input bg-background text-foreground hover:border-[#b5bfcc] hover:bg-subtle hover:text-foreground dark:bg-input/30 dark:hover:bg-input/50",
        secondary:
          "bg-secondary text-secondary-foreground hover:bg-secondary/80 hover:text-secondary-foreground",
        ghost:
          "text-link hover:bg-primary-soft hover:text-link-hover dark:hover:bg-accent/50",
        link: "h-auto px-0 text-link underline-offset-2 hover:text-link-hover hover:underline",
      },
      size: {
        default: "h-9 px-3.5",
        xs: "h-6 gap-1 rounded-sm px-2 text-micro has-[>svg]:px-1.5 [&_svg:not([class*='size-'])]:size-3",
        sm: "h-[30px] gap-1.5 px-2.5 text-label [&_svg:not([class*='size-'])]:size-[15px]",
        lg: "h-10 px-[18px]",
        icon: "size-9",
        "icon-xs": "size-6 rounded-sm [&_svg:not([class*='size-'])]:size-3",
        "icon-sm": "size-7 [&_svg:not([class*='size-'])]:size-4",
        "icon-lg": "size-10",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

// forwardRef: WordPress ships React 18, where function components do not
// receive `ref` as a prop (Radix asChild triggers need it).
const Button = React.forwardRef<
  HTMLButtonElement,
  React.ComponentPropsWithoutRef<"button"> &
    VariantProps<typeof buttonVariants> & {
      asChild?: boolean
    }
>(function Button(
  { className, variant = "default", size = "default", asChild = false, ...props },
  ref
) {
  const Comp = asChild ? Slot.Root : "button"

  return (
    <Comp
      ref={ref}
      data-slot="button"
      data-variant={variant}
      data-size={size}
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
})

export { Button, buttonVariants }
