import * as React from "react"
import { cn } from "@/admin/lib/utils"

// forwardRef: React 18 (WordPress) does not pass `ref` as a prop, and
// react-hook-form's register() needs it.
const Textarea = React.forwardRef<
  HTMLTextAreaElement,
  React.ComponentPropsWithoutRef<"textarea">
>(function Textarea({ className, ...props }, ref) {
  return (
    <textarea
      ref={ref}
      data-slot="textarea"
      className={cn(
        "flex min-h-16 w-full rounded-md border border-input bg-background px-3 py-2 text-body text-foreground shadow-none transition-[color,box-shadow,border-color] outline-none placeholder:text-faint hover:border-[#b5bfcc] disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60 dark:bg-input/30",
        "focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-brand-soft focus-visible:outline-none",
        "aria-invalid:border-destructive aria-invalid:ring-[3px] aria-invalid:ring-destructive-soft dark:aria-invalid:ring-destructive/40",
        className
      )}
      {...props}
    />
  )
})

export { Textarea }
