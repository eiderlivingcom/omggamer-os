import type { Metadata } from "next";
import { ModulePage } from "@/components/module-page";

export const metadata: Metadata = { title: "Orders" };

export default function Page() {
  return <ModulePage href="/orders" description="Customer orders from WooCommerce, supplier fulfilment and key delivery, failures and refunds." />;
}
