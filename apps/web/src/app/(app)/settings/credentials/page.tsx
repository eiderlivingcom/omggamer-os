import { Card, Empty, Pill } from "@/components/ui";

export default function CredentialsPage() {
  return (
    <Card title="API credentials" aside={<Pill>Phase 2</Pill>}>
      <Empty title="No credentials stored yet">
        The Kinguin API key is added here in Phase 2, followed by the OpenAI key (Phase 4) and the WooCommerce connector key
        (Phase 5). Keys are encrypted at rest and can be replaced but never read back.
      </Empty>
    </Card>
  );
}
