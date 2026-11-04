import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "AI Studio" };

export default function Page() {
  return <ModulePage href="/ai-studio" description="AI-written product text and SEO with versioned prompts, a job queue, and token and cost tracking." />;
}
