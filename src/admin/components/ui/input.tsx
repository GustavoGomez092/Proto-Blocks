import * as React from "react"
import { cn } from "@/admin/lib/utils"

// forwardRef: React 18 (WordPress) does not pass `ref` as a prop, and
// react-hook-form's register() needs it.
const Input = React.forwardRef<
  HTMLInputElement,
  React.ComponentPropsWithoutRef<"input">
>(function Input({ className, type, ...props }, ref) {
  return (
    <input
      ref={ref}
      type={type}
      data-slot="input"
      className={cn(
        "h-[38px] w-full min-w-0 rounded-md border border-input bg-background px-3 py-1 text-body text-foreground shadow-none transition-[color,box-shadow,border-color] outline-none selection:bg-primary selection:text-primary-foreground file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-faint hover:border-[#b5bfcc] disabled:pointer-events-none disabled:cursor-not-allowed disabled:bg-muted disabled:opacity-60 dark:bg-input/30",
        "focus-visible:border-primary focus-visible:ring-[3px] focus-visible:ring-brand-soft focus-visible:outline-none",
        "aria-invalid:border-destructive aria-invalid:ring-[3px] aria-invalid:ring-destructive-soft dark:aria-invalid:ring-destructive/40",
        className
      )}
      {...props}
    />
  )
})

export { Input }
