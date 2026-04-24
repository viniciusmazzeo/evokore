-- Evolucao de plan_sales para reconciliacao automatica via webhook EVO.
ALTER TABLE plan_sales
  ADD COLUMN IF NOT EXISTS evo_sale_id BIGINT NULL AFTER payment_link,
  ADD COLUMN IF NOT EXISTS evo_member_id BIGINT NULL AFTER evo_sale_id,
  ADD COLUMN IF NOT EXISTS payment_reference VARCHAR(100) NULL AFTER evo_member_id,
  ADD COLUMN IF NOT EXISTS last_webhook_at DATETIME NULL AFTER payment_reference,
  ADD COLUMN IF NOT EXISTS last_webhook_payload_json JSON NULL AFTER last_webhook_at;

CREATE INDEX IF NOT EXISTS idx_plan_sales_evo_sale_id ON plan_sales (evo_sale_id);
CREATE INDEX IF NOT EXISTS idx_plan_sales_evo_member_id ON plan_sales (evo_member_id);
CREATE INDEX IF NOT EXISTS idx_plan_sales_payment_reference ON plan_sales (payment_reference);
