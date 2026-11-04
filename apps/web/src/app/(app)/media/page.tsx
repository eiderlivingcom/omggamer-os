import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Media" };

export default function Page() {
  return <ModulePage href="/media" description="Product images and screenshots, validated and stored once, then reused on every storefront." />;
}
