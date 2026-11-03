import { createPortal } from "react-dom"
import {
  CircleCheckIcon,
  CircleXIcon,
  InfoIcon,
  Loader2Icon,
  TriangleAlertIcon,
} from "lucide-react"
import { Toaster as Sonner, type ToasterProps } from "sonner"

import { usePortalContainer } from "@/admin/components/shell/PortalProvider"

// Light theme only (no next-themes). Rendered into the admin portal node so
// the scoped tokens apply to toasts too.
const Toaster = ({ ...props }: ToasterProps) => {
  const container = usePortalContainer()

  const toaster = (
    <Sonner
      theme="light"
      position="bottom-right"
      className="toaster group"
      icons={{
        success: (
          <CircleCheckIcon className="size-4 text-success" aria-hidden="true" />
        ),
        info: <InfoIcon className="size-4" aria-hidden="true" />,
        warning: (
          <TriangleAlertIcon className="size-4 text-warning" aria-hidden="true" />
        ),
        error: (
          <CircleXIcon className="size-4 text-destructive" aria-hidden="true" />
        ),
        loading: (
          <Loader2Icon
            className="size-4 animate-spin motion-reduce:animate-none"
            aria-hidden="true"
          />
        ),
      }}
      style={
        {
          "--normal-bg": "var(--popover)",
          "--normal-text": "var(--popover-foreground)",
          "--normal-border": "var(--border)",
          "--border-radius": "var(--radius)",
        } as React.CSSProperties
      }
      {...props}
    />
  )

  return container ? createPortal(toaster, container) : toaster
}

export { Toaster }
