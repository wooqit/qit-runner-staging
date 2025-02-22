WC_PAY=$(echo "$PLUGIN_ACTIVATION_STACK" | grep -o woocommerce-payments)

if [ "$WC_PAY" == ""  ]; then
  echo "wc_pay_required=0" >> $GITHUB_OUTPUT
else
  echo "wc_pay_required=1" >> $GITHUB_OUTPUT
fi