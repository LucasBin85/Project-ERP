// resources/js/types.ts
export interface TreeNode {
  id: number
  code: string
  name: string
  type?: string
  parent_id: number | null
  is_system?: boolean
  allows_posting?: boolean
  financial_group?: string | null
  is_synthetic?: boolean
  children?: TreeNode[]
}
