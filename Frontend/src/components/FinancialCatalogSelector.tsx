import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Card, Field, Subtitle1, Caption1, Button, Badge } from '@fluentui/react-components';
import { CheckmarkCircleFilled, OpenRegular, BuildingBankRegular } from '@fluentui/react-icons';
import { api } from '../api';
import type { CatalogInstitution, CatalogProduct, CatalogProductVariant } from '../types';

interface CatalogSelectionResult {
  institution: CatalogInstitution;
  product: CatalogProduct;
  variant: CatalogProductVariant;
}

interface FinancialCatalogSelectorProps {
  categoryFilter?: string;
  onSelectProduct: (result: CatalogSelectionResult) => void;
  onCancelManual?: () => void;
}

export function FinancialCatalogSelector({ categoryFilter, onSelectProduct, onCancelManual }: FinancialCatalogSelectorProps) {
  const [selectedInstId, setSelectedInstId] = useState<string>('');
  const [selectedCategory, setSelectedCategory] = useState<string>(categoryFilter ?? 'credit_card');
  const [selectedProductId, setSelectedProductId] = useState<string>('');
  const [selectedVariantId, setSelectedVariantId] = useState<string>('');
  const [searchQuery, setSearchQuery] = useState<string>('');

  const institutionsQuery = useQuery({
    queryKey: ['catalog-institutions'],
    queryFn: () => api.listCatalogInstitutions(),
  });

  const categoriesQuery = useQuery({
    queryKey: ['catalog-categories'],
    queryFn: api.listCatalogCategories,
  });

  const productsQuery = useQuery({
    queryKey: ['catalog-products', selectedInstId, selectedCategory, searchQuery],
    queryFn: () => api.listCatalogProducts(selectedInstId, selectedCategory, searchQuery),
  });

  const variantsQuery = useQuery({
    queryKey: ['catalog-variants', selectedProductId],
    queryFn: () => api.listCatalogVariants(selectedProductId),
    enabled: Boolean(selectedProductId),
  });

  const selectedInst = institutionsQuery.data?.find((inst) => String(inst.id) === selectedInstId);
  const selectedProd = productsQuery.data?.find((prod) => String(prod.id) === selectedProductId);
  const selectedVar = variantsQuery.data?.find((v) => String(v.id) === selectedVariantId);

  const handleConfirm = () => {
    if (selectedInst && selectedProd && selectedVar) {
      onSelectProduct({
        institution: selectedInst,
        product: selectedProd,
        variant: selectedVar,
      });
    }
  };

  return (
    <Card className="inline-form catalog-selector-card" style={{ border: '1px solid var(--primary)', background: 'var(--raised)' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <div>
          <Subtitle1 style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>
            <BuildingBankRegular style={{ color: 'var(--primary)' }} />
            Catálogo Bancario Oficial de República Dominicana
          </Subtitle1>
          <Caption1>Productos y condiciones verificadas por la Superintendencia de Bancos</Caption1>
        </div>
        {onCancelManual && (
          <Button appearance="subtle" size="small" onClick={onCancelManual}>
            Entrada Manual
          </Button>
        )}
      </div>

      <div className="form-grid" style={{ marginTop: '12px' }}>
        {/* Step 1: Select Institution */}
        <Field label="1. Institución Financiera (SB-RD)">
          <select
            value={selectedInstId}
            onChange={(e) => {
              setSelectedInstId(e.target.value);
              setSelectedProductId('');
              setSelectedVariantId('');
            }}
          >
            <option value="">Todas las instituciones</option>
            {institutionsQuery.data?.map((inst) => (
              <option key={inst.id} value={inst.id}>
                {inst.name}
              </option>
            ))}
          </select>
        </Field>

        {/* Step 2: Select Category */}
        <Field label="2. Categoría de Producto">
          <select
            value={selectedCategory}
            onChange={(e) => {
              setSelectedCategory(e.target.value);
              setSelectedProductId('');
              setSelectedVariantId('');
            }}
          >
            {categoriesQuery.data?.map((cat) => (
              <option key={cat.code} value={cat.code}>
                {cat.label}
              </option>
            ))}
          </select>
        </Field>

        {/* Search Query Filter */}
        <Field label="Buscar Producto / Nombre">
          <input
            type="text"
            placeholder="ej. Visa Gold, Crediclick, ExtraCrédito..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
        </Field>

        {/* Step 3: Select Product */}
        <Field label="3. Producto Verificado">
          <select
            value={selectedProductId}
            onChange={(e) => {
              setSelectedProductId(e.target.value);
              setSelectedVariantId('');
            }}
          >
            <option value="">-- Seleccionar Producto --</option>
            {productsQuery.data?.map((prod) => (
              <option key={prod.id} value={prod.id}>
                {prod.name} ({prod.institution?.name ?? ''})
              </option>
            ))}
          </select>
        </Field>

        {/* Step 4: Select Variant */}
        {selectedProductId && (
          <Field label="4. Variante / Plan">
            <select
              value={selectedVariantId}
              onChange={(e) => setSelectedVariantId(e.target.value)}
            >
              <option value="">-- Seleccionar Variante --</option>
              {variantsQuery.data?.map((v) => (
                <option key={v.id} value={v.id}>
                  {v.variant_name} ({v.annual_interest_rate ? `Tasa: ${v.annual_interest_rate}%` : 'Condición Estándar'})
                </option>
              ))}
            </select>
          </Field>
        )}
      </div>

      {/* Verified Conditions Panel */}
      {selectedVar && selectedProd && selectedInst && (
        <div className="verified-conditions-panel" style={{ marginTop: '16px', padding: '14px', background: 'var(--glass-bg)', borderRadius: '12px', border: '1px solid var(--border)' }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px' }}>
            {(() => {
              const status = (selectedVar as any).verification_status || (selectedProd as any).verification_status || (selectedProd.verified ? 'verified' : 'pending_review');
              if (status === 'verified') {
                return (
                  <Badge appearance="tint" color="success" icon={<CheckmarkCircleFilled />}>
                    VERIFICADO OFICIALMENTE
                  </Badge>
                );
              }
              if (status === 'pending_review') {
                return (
                  <Badge appearance="tint" color="warning">
                    PENDIENTE DE REVISIÓN
                  </Badge>
                );
              }
              if (status === 'historical') {
                return (
                  <Badge appearance="tint">
                    HISTÓRICO
                  </Badge>
                );
              }
              return (
                <Badge appearance="tint" color="danger">
                  SIN DATOS SUFICIENTES
                </Badge>
              );
            })()}
            {selectedProd.official_source && (
              <a href={selectedProd.official_source} target="_blank" rel="noopener noreferrer" style={{ fontSize: '11px', color: 'var(--primary)', display: 'flex', alignItems: 'center', gap: '4px' }}>
                Ver publicación oficial <OpenRegular />
              </a>
            )}
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '10px', fontSize: '12px' }}>
            <div><strong>Banco:</strong> {selectedInst.name}</div>
            <div><strong>Producto:</strong> {selectedProd.name}</div>
            <div><strong>Tasa de Interés:</strong> {selectedVar.annual_interest_rate ? `${selectedVar.annual_interest_rate}% anual` : 'No aplica / Estándar'}</div>
            <div><strong>Días de Gracia:</strong> {selectedVar.grace_days} días</div>
            <div><strong>Comisión Sobregiro:</strong> {selectedVar.overdraft_fee_type === 'none' ? 'Sin comisión' : selectedVar.overdraft_fee_type === 'fixed' ? `RD$ ${selectedVar.overdraft_fee_amount} (Fija)` : `${selectedVar.overdraft_fee_percentage}%`}</div>
          </div>

          <Button appearance="primary" style={{ marginTop: '14px', width: '100%' }} onClick={handleConfirm}>
            Cargar Datos Verificados al Formulario
          </Button>
        </div>
      )}
    </Card>
  );
}
