INSERT INTO clients (name, legal_name, document_type, document_number, status)
VALUES ('Panobianco', 'Panobianco Franchising LTDA', 'CNPJ', NULL, 'ACTIVE')
ON DUPLICATE KEY UPDATE
  legal_name = VALUES(legal_name),
  status = VALUES(status);

SET @client_id := (SELECT id FROM clients WHERE name = 'Panobianco' LIMIT 1);

INSERT INTO units (client_id, unit_code, unit_name, status, timezone)
VALUES
  (@client_id, 'PAN-CAMPINAS-CENTRO', 'Panobianco Campinas Centro', 'ACTIVE', 'America/Sao_Paulo'),
  (@client_id, 'PAN-RIBEIRAO-SUL', 'Panobianco Ribeirao Sul', 'ACTIVE', 'America/Sao_Paulo')
ON DUPLICATE KEY UPDATE
  unit_name = VALUES(unit_name),
  status = VALUES(status),
  timezone = VALUES(timezone);

SET @unit_1 := (
  SELECT id FROM units
  WHERE client_id = @client_id AND unit_code = 'PAN-CAMPINAS-CENTRO'
  LIMIT 1
);

SET @unit_2 := (
  SELECT id FROM units
  WHERE client_id = @client_id AND unit_code = 'PAN-RIBEIRAO-SUL'
  LIMIT 1
);

DELETE FROM unit_evo_credentials
WHERE unit_id IN (@unit_1, @unit_2);

INSERT INTO unit_evo_credentials (unit_id, evo_dns, token_encrypted, token_hint, is_active)
VALUES
  (@unit_1, 'PANOBIANCOS', '__REPLACE_WITH_UNIT_1_TOKEN__', '...NTR0', 1),
  (@unit_2, 'PANOBIANCOS', '__REPLACE_WITH_UNIT_2_TOKEN__', '...NTR1', 1);
