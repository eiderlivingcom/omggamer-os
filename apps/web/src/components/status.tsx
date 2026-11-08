import { Pill } from "./ui";

type Tone = "ok" | "warn" | "bad" | "info" | "muted";

const PRODUCT: Record<string, [Tone, string]> = {
  draft: ["muted", "Draft"],
  review: ["warn", "In review"],
  approved: ["ok", "Approved"],
  published: ["ok", "Published"],
  unpublished: ["muted", "Unpublished"],
  archived: ["muted", "Archived"],
  merged: ["muted", "Merged"],
};
const AI: Record<string, [Tone, string]> = {
  none: ["muted", "No AI content"],
  draft: ["info", "AI draft"],
  pending_review: ["warn", "AI: needs review"],
  approved: ["ok", "AI approved"],
  rejected: ["bad", "AI rejected"],
  superseded: ["muted", "Superseded"],
};
const PUBLISH: Record<string, [Tone, string]> = {
  not_published: ["muted", "Not published"],
  pending: ["info", "Publishing…"],
  published: ["ok", "Live"],
  failed: ["bad", "Sync failed"],
  unpublished: ["muted", "Unpublished"],
};

function pill(map: Record<string, [Tone, string]>, key: string | null | undefined, fallback: string) {
  const [tone, label] = map[key ?? fallback] ?? map[fallback] ?? ["muted", key ?? "—"];
  return <Pill tone={tone}>{label}</Pill>;
}

export const ProductStatus = ({ value }: { value: string }) => pill(PRODUCT, value, "draft");
export const AiStatus = ({ value }: { value: string | null }) => pill(AI, value, "none");
export const PublishStatus = ({ value }: { value: string }) => pill(PUBLISH, value, "not_published");

export function money(amount: string | null | undefined, currency?: string | null) {
  if (amount === null || amount === undefined) return "—";
  const n = Number(amount);
  try {
    return new Intl.NumberFormat(undefined, { style: "currency", currency: currency || "EUR" }).format(n);
  } catch {
    return `${n.toFixed(2)} ${currency ?? ""}`;
  }
}
