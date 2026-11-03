import { createPortal } from "react-dom"
import {
  CheckIcon,
  InfoIcon,
  Loader2Icon,
  TriangleAlertIcon,
  XIcon,
} from "lucide-react"
import { Toaster as Sonner, type ToasterProps } from "sonner"

import { usePortalContainer } from "@/admin/components/shell/PortalProvider"

const ic = "size-4 stroke-2"

const Badge = ({ tone, children }: { tone: string; children: React.ReactNode }) => (
  <span className={`flex size-7 items-center justify-center rounded-full ${tone}`}>
    {children}
  </span>
)

// Light theme only (no next-themes). Rendered into the admin portal node so
// the scoped tokens apply to toasts too.
const Toaster = ({ ...props }: ToasterProps) => {
  const container = usePortalContainer()

  const toaster = (
    <Sonner
      theme="light"
      position="bottom-right"
      className="toaster group"
      offset={{ right: 24, bottom: 56 }}
      mobileOffset={{ right: 16, left: 16, bottom: 56 }}
      closeButton
      icons={{
        success: <Badge tone="bg-[#e6f8f7] text-[#0b5f5a]"><CheckIcon className={ic} aria-hidden="true" /></Badge>,
        info: <Badge tone="bg-[#f1f5fa] text-[#1a4b85]"><InfoIcon className={ic} aria-hidden="true" /></Badge>,
        warning: <Badge tone="bg-warning-soft text-warning"><TriangleAlertIcon className={ic} aria-hidden="true" /></Badge>,
        error: <Badge tone="bg-destructive-soft text-destructive"><XIcon className={ic} aria-hidden="true" /></Badge>,
        loading: <Badge tone="bg-accent text-muted-foreground"><Loader2Icon className={`${ic} animate-spin motion-reduce:animate-none`} aria-hidden="true" /></Badge>,
      }}
      toastOptions={{
        unstyled: true,
        classNames: {
          toast:
            "group/toast relative flex w-[var(--width)] min-w-[320px] max-w-[420px] items-start gap-3 rounded-[10px] border border-border bg-card px-4 py-3.5 font-sans text-card-foreground shadow-[0_12px_32px_-12px_rgba(15,29,46,.35)]",
          icon: "m-0 flex shrink-0",
          content: "flex min-w-0 flex-1 flex-col gap-0.5 pr-4",
          title: "text-[14px] font-semibold leading-5 text-foreground",
          description: "text-[12.5px] leading-[18px] text-muted-foreground",
          actionButton:
            "ml-auto inline-flex h-7 shrink-0 cursor-pointer items-center rounded-md border border-border bg-card px-2.5 text-[12.5px] font-medium text-foreground hover:bg-accent",
          cancelButton:
            "inline-flex h-7 shrink-0 cursor-pointer items-center rounded-md px-2.5 text-[12.5px] text-muted-foreground hover:bg-accent",
          closeButton:
            "pb-toast-close absolute right-2 top-2 flex size-5 cursor-pointer items-center justify-center rounded border-0 bg-transparent text-muted-foreground opacity-0 transition-opacity hover:bg-accent group-hover/toast:opacity-100 focus-visible:opacity-100",
        },
      }}
      {...props}
    />
  )

  return container ? createPortal(toaster, container) : toaster
}

export { Toaster }
