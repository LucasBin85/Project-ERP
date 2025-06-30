// resources/js/types.ts
export interface TreeNode {
  id: number
  parent_id: number | null
  code: string
  name: string
  type: string
  is_protected: boolean
  children: TreeNode[]
}
