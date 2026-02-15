# Planned Entity Support

Implementation plan for additional entity types.

## Priority Order

Based on dependencies and common use cases:

```
1. Customer Groups       (foundation - customers depend on this)
2. Attributes            (foundation - products depend on this)
3. Attribute Sets        (foundation - products depend on this)
4. CMS Blocks            (no dependencies)
5. CMS Pages             (may reference blocks)
6. Reviews & Ratings     (depends on products + customers)
7. Cart Price Rules      (depends on customer groups, products)
```

---

## 1. Customer Groups

**Tables:**
- `customer_group`

**Complexity:** Low

**Implementation:**

```php
// Source module observer
<customer_group_save_after>
    <observers>
        <maho_datasynctracker_customer_group>
            <class>maho_datasynctracker/observer</class>
            <method>trackCustomerGroup</method>
        </maho_datasynctracker_customer_group>
    </observers>
</customer_group_save_after>

// Entity handler
class Maho_DataSync_Model_Entity_CustomerGroup extends Maho_DataSync_Model_Entity_Abstract
{
    protected $_entityType = 'customer_group';

    // Simple: customer_group_id, customer_group_code, tax_class_id
}
```

**Data structure:**
```php
[
    'customer_group_id' => 4,
    'customer_group_code' => 'Wholesale',
    'tax_class_id' => 3,
]
```

---

## 2. Product Attributes

**Tables:**
- `eav_attribute`
- `catalog_eav_attribute`
- `eav_attribute_option`
- `eav_attribute_option_value`
- `eav_attribute_option_swatch` (if using swatches)

**Complexity:** Medium

**Implementation:**

```php
// Entity handler needs to:
// 1. Create/update eav_attribute
// 2. Create/update catalog_eav_attribute (catalog-specific settings)
// 3. Sync attribute options (for select/multiselect)
// 4. Sync option swatches if applicable
```

**Data structure:**
```php
[
    'attribute_id' => 134,
    'attribute_code' => 'brand',
    'frontend_input' => 'select',
    'frontend_label' => 'Brand',
    'is_required' => 0,
    'is_user_defined' => 1,
    'is_searchable' => 1,
    'is_filterable' => 1,
    'is_comparable' => 1,
    'is_visible_on_front' => 1,
    'is_configurable' => 0,
    'options' => [
        ['value' => 'wilson', 'label' => 'Wilson', 'sort_order' => 1],
        ['value' => 'babolat', 'label' => 'Babolat', 'sort_order' => 2],
    ],
]
```

**Challenges:**
- Option IDs differ between systems (need registry mapping)
- Swatch data has separate storage
- Must handle both admin and store-specific labels

---

## 3. Attribute Sets

**Tables:**
- `eav_attribute_set`
- `eav_attribute_group`
- `eav_entity_attribute` (attribute-to-group assignment)

**Complexity:** Medium

**Dependencies:** Attributes must be synced first

**Implementation:**

```php
// Entity handler needs to:
// 1. Create/update attribute set
// 2. Create/update attribute groups within set
// 3. Assign attributes to groups
```

**Data structure:**
```php
[
    'attribute_set_id' => 9,
    'attribute_set_name' => 'Tennis Equipment',
    'entity_type_id' => 4, // catalog_product
    'groups' => [
        [
            'attribute_group_name' => 'General',
            'sort_order' => 1,
            'attributes' => ['name', 'sku', 'price', 'status'],
        ],
        [
            'attribute_group_name' => 'Tennis Specs',
            'sort_order' => 10,
            'attributes' => ['brand', 'grip_size', 'head_size'],
        ],
    ],
]
```

---

## 4. CMS Blocks

**Tables:**
- `cms_block`
- `cms_block_store`

**Complexity:** Low

**Implementation:**

```php
// Source module observer
<cms_block_save_after>
    <observers>
        <maho_datasynctracker_cms_block>
            <class>maho_datasynctracker/observer</class>
            <method>trackCmsBlock</method>
        </maho_datasynctracker_cms_block>
    </observers>
</cms_block_save_after>
```

**Data structure:**
```php
[
    'block_id' => 5,
    'identifier' => 'footer_links',
    'title' => 'Footer Links',
    'content' => '<ul>...</ul>',
    'is_active' => 1,
    'stores' => [0, 1], // Store IDs (0 = all stores)
]
```

**Challenges:**
- Content may contain widget directives with entity IDs
- Store assignment mapping

---

## 5. CMS Pages

**Tables:**
- `cms_page`
- `cms_page_store`

**Complexity:** Low

**Dependencies:** CMS Blocks (pages may embed blocks)

**Implementation:**

```php
// Source module observer
<cms_page_save_after>
    <observers>
        <maho_datasynctracker_cms_page>
            <class>maho_datasynctracker/observer</class>
            <method>trackCmsPage</method>
        </maho_datasynctracker_cms_page>
    </observers>
</cms_page_save_after>
```

**Data structure:**
```php
[
    'page_id' => 12,
    'identifier' => 'about-us',
    'title' => 'About Us',
    'content_heading' => 'About Our Company',
    'content' => '<p>...</p>',
    'is_active' => 1,
    'sort_order' => 0,
    'layout_update_xml' => '',
    'root_template' => 'one_column',
    'meta_keywords' => '',
    'meta_description' => '',
    'stores' => [0],
]
```

---

## 6. Reviews & Ratings

**Tables:**
- `review`
- `review_detail`
- `review_entity_summary`
- `rating`
- `rating_option`
- `rating_option_vote`

**Complexity:** High

**Dependencies:** Products, Customers

**Implementation:**

```php
// Source module observers
<review_save_after>
<rating_vote_save_after>
```

**Data structure:**
```php
[
    'review_id' => 456,
    'product_sku' => 'DEMO-RACKET-PRO', // Use SKU for cross-system mapping
    'customer_email' => 'john@example.com', // Use email for mapping (or null for guest)
    'nickname' => 'TennisPlayer',
    'title' => 'Great racket!',
    'detail' => 'Love this racket, perfect weight and balance.',
    'status_id' => 1, // Approved
    'created_at' => '2024-01-15 10:30:00',
    'stores' => [1],
    'ratings' => [
        ['rating_code' => 'Quality', 'value' => 5],
        ['rating_code' => 'Value', 'value' => 4],
        ['rating_code' => 'Price', 'value' => 4],
    ],
]
```

**Challenges:**
- Product ID mapping (use SKU)
- Customer ID mapping (use email)
- Rating entity mapping
- Review summary recalculation after import

---

## 7. Cart Price Rules

**Tables:**
- `salesrule`
- `salesrule_coupon`
- `salesrule_website`
- `salesrule_customer_group`
- `salesrule_product_attribute`
- `salesrule_label`

**Complexity:** High

**Dependencies:** Customer Groups, Product Attributes

**Implementation:**

```php
// Source module observer
<salesrule_save_after>
    <observers>
        <maho_datasynctracker_salesrule>
            <class>maho_datasynctracker/observer</class>
            <method>trackSalesRule</method>
        </maho_datasynctracker_salesrule>
    </observers>
</salesrule_save_after>
```

**Data structure:**
```php
[
    'rule_id' => 10,
    'name' => 'Summer Sale 20% Off',
    'description' => 'Get 20% off all tennis equipment',
    'is_active' => 1,
    'from_date' => '2024-06-01',
    'to_date' => '2024-08-31',
    'uses_per_customer' => 1,
    'uses_per_coupon' => 0,
    'customer_group_ids' => [0, 1, 2], // Map by group code
    'website_ids' => [1],
    'conditions_serialized' => '...', // Needs attribute ID remapping!
    'actions_serialized' => '...',
    'simple_action' => 'by_percent',
    'discount_amount' => 20,
    'stop_rules_processing' => 0,
    'sort_order' => 0,
    'coupons' => [
        ['code' => 'SUMMER20', 'usage_limit' => 1000, 'usage_per_customer' => 1],
    ],
    'labels' => [
        ['store_id' => 0, 'label' => '20% Off Summer Sale'],
    ],
]
```

**Challenges:**
- Serialized conditions contain attribute IDs that need remapping
- Website/store mapping
- Customer group mapping
- Coupon usage tracking (may not want to sync)

---

## Implementation Checklist

For each new entity type:

### Source Module (Maho_DataSyncTracker)
- [ ] Add event observer to config.xml
- [ ] Add observer method to Observer.php
- [ ] Test tracking is working

### Destination Module (Maho_DataSync)
- [ ] Create Entity Handler class
- [ ] Register in config.xml
- [ ] Add to OpenMage adapter fetch methods
- [ ] Add to ENTITY_ORDER constant in DatasyncIncremental.php
- [ ] Add to ENTITY_MAP constant
- [ ] Create registry mappings as needed
- [ ] Add validation rules
- [ ] Write tests

### Documentation
- [ ] Add to Supported Entities table
- [ ] Add CSV example
- [ ] Add PHP array example
- [ ] Update IMPORT_ORDER.md

---

## Estimated Effort

| Entity | Effort | Incremental Support |
|--------|--------|---------------------|
| Customer Groups | 2-4 hours | Yes |
| Attributes | 8-16 hours | Yes |
| Attribute Sets | 4-8 hours | Yes |
| CMS Blocks | 2-4 hours | Yes |
| CMS Pages | 2-4 hours | Yes |
| Reviews & Ratings | 8-12 hours | Yes |
| Cart Price Rules | 12-20 hours | Yes (but conditions tricky) |

**Total estimated:** 38-68 hours
