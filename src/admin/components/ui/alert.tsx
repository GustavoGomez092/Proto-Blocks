import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { cn } from "@/admin/lib/utils"

const alertVariants = cva(
  "relative grid w-full grid-cols-[0_1fr] items-start gap-y-0.5 rounded-xl border px-4 py-3 text-label has-[>svg]:grid-cols-[calc(var(--spacing)*4)_1fr] has-[>svg]:gap-x-3 [&>svg]:size-4 [&>svg]:translate-y-0.5 [&>svg]:text-current",
  {
    variants: {
      variant: {
        default: "bg-card text-card-foreground [&>svg]:text-primary",
        destructive:
          "border-destructive/30 bg-destructive-soft text-foreground *:data-[slot=alert-description]:text-foreground/80 [&>svg]:text-destructive",
        success:
          "border-success/25 bg-success-soft text-foreground *:data-[slot=alert-description]:text-foreground/80 [&>svg]:text-success",
        info: "border-primary/20 bg-primary-soft text-foreground *:data-[slot=alert-description]:text-foreground/80 [&>svg]:text-primary",
        // Proto-Blocks addition: read-only banners and non-blocking cautions.
        warning:
          "border-warning-border bg-warning-soft text-foreground [&>svg]:text-warning *:data-[slot=alert-description]:text-foreground/80",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Alert({
  className,
  variant,
  ...props
}: React.ComponentProps<"div"> & VariantProps<typeof alertVariants>) {
  return (
    <div
      data-slot="alert"
      role="alert"
      className={cn(alertVariants({ variant }), className)}
      {...props}
    />
  )
}

function AlertTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-title"
      className={cn(
        "col-start-2 line-clamp-1 min-h-4 font-medium",
        className
      )}
      {...props}
    />
  )
}

function AlertDescription({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="alert-description"
      className={cn(
        "col-start-2 grid justify-items-start gap-1 text-label text-muted-foreground [&_p]:leading-relaxed",
        className
      )}
      {...props}
    />
  )
}

export { Alert, AlertTitle, AlertDescription }
