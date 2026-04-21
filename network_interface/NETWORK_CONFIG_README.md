---
# Network Interface Configuration with QinQ Support
# =============================================================================
#
# Files created:
#   1. playbooks/network_interface_config.yml - Main Ansible playbook
#   2. tmp/network_config_exec.py - Python execution script
#   3. hosts/switch_hosts - Example inventory
#   4. playbooks/network_interface_config_example.yml - Example usage
#
# =============================================================================
# USAGE
# =============================================================================
#
# 1. Edit inventory file:
#    vi hosts/switch_hosts
#    # Add your device IP, username, password
#
# 2. Run playbook:
#    ansible-playbook -i hosts/switch_hosts playbooks/network_interface_config.yml \
#      -e config_json='{...}'
#
# 3. Or use example:
#    ansible-playbook -i hosts/switch_hosts playbooks/network_interface_config_example.yml
#
# =============================================================================
# INPUT FORMAT
# =============================================================================
#
# config_json format:
# {
#   "hostname": "switch1",
#   "interfaces": [
#     {
#       "name": "GigabitEthernet0/9",
#       "description": "Description text",
#       "spanning_tree": false,        # true = enabled, false = disabled
#       "mode": "trunk",             # trunk|dot1q-tunnel-uplink|dot1q-translating-tunnel
#       "allowed_vlans": "100-200", # VLAN range/list
#       "pvid": 100,                # native VLAN (optional)
#       "qinq_rules": [             # QinQ translation rules
#         { "from": 100, "to": 1, "cos": 0 }
#       ],
#       "lacp": false              # LACP aggregation
#     }
#   ]
# }
#
# =============================================================================
# SUPPORTED MODES
# =============================================================================
#
# - trunk                   : Standard trunk port
# - dot1q-tunnel-uplink   : QinQ uplink port
# - dot1q-translating-tunnel : QinQ translation mode
#
# =============================================================================
# OUTPUT CLI COMMANDS GENERATED
# =============================================================================
#
# Example output for trunk mode:
#   interface GigabitEthernet0/10
#    description Tester
#    no spanning-tree
#    switchport mode trunk
#    switchport trunk vlan-allowed 2225
#    switchport pvid 2225
#    !
#
# Example output for dot1q-translating-tunnel:
#   interface GigabitEthernet0/11
#    description QinQ_customer
#    no spanning-tree
#    switchport mode dot1q-translating-tunnel
#    switchport trunk vlan-allowed 100-200
#    switchport pvid 100
#    switchport dot1q-translating-tunnel mode QinQ translate 100 1 0
#    switchport dot1q-translating-tunnel mode QinQ translate 200 2 1
#    !
#
# =============================================================================
# END



ansible-playbook -i hosts/192.168.200.245.yml playbooks/network_interface_config.yml \
  -e 'config_json={"hostname":"bridge1","interfaces":[{"name":"g0/88","description":"Nokia_Router","spanning_tree":false,"mode":"dot1q-tunnel-uplink","allowed_vlans":"2001-3000,4000","pvid":null,"qinq_rules":[]}]}'

#ansible-playbook -i hosts/192.168.200.245.yml playbooks/network_interface_config.yml 


{
  "interfaces": [
    {
      "name": "GigabitEthernet0/9",
      "description": "Nokia_Router",
      "spanning_tree": false,
      "mode": "dot1q-tunnel-uplink",
      "allowed_vlans": "2001-3000,4000",
      "pvid": null,
      "qinq_rules": []
    },
    {
      "name": "GigabitEthernet0/10",
      "description": "Tester",
      "spanning_tree": false,
      "mode": "trunk",
      "allowed_vlans": "2225",
      "pvid": 2225,
      "qinq_rules": []
    }
  ]
}