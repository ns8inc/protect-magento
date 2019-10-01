import { SwitchContext } from "ns8-switchboard-interfaces";
import { RestClient, Order, Customer, Transaction as MagentoTransaction } from '@ns8/magento2-rest-client';
import { ServiceIntegration, Transaction } from "ns8-protect-models";
export class MagentoClient {

  private SwitchContext: SwitchContext;
  public client: RestClient;
  constructor(switchContext: SwitchContext) {
    this.SwitchContext = switchContext;
    let siTemp = this.SwitchContext.merchant.serviceIntegrations.find((integration) => {
      return integration.type === 'MAGENTO';
    });
    if (!siTemp) throw new Error('No Magento Service Integration defined on this merchant');
    const si: ServiceIntegration = siTemp;

    this.client = new RestClient({
      url: `${this.SwitchContext.merchant.storefrontUrl}\rest`,
      consumerKey: si.identityToken,
      consumerSecret: si.identitySecret,
      accessToken: si.token,
      accessTokenSecret: si.secret
    })
  }

  public getOrder = async (id: number): Promise<Order> => {
    return await this.client.orders.get(id);
  }

  public getCustomer = async (id: number): Promise<Customer> => {
    return await this.client.customers.get(id);
  }

  public getTransaction = async (id: string): Promise<MagentoTransaction> => {
    return await this.client.transactions.get(id);
  }
}
